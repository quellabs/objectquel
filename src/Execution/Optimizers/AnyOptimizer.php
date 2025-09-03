<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	
	/**
	 * Optimizes JOIN types based on WHERE clause analysis.
	 * Converts LEFT JOINs to INNER JOINs when safe, and vice versa.
	 */
	class AnyOptimizer {
		
		/**
		 * Entity metadata store for field nullability checks
		 */
		private EntityStore $entityStore;
		private AstNodeReplacer $nodeReplacer;
		private RangeUsageAnalyzer $analyzer;
		
		/**
		 * Initialize optimizer with entity metadata access
		 *
		 * @param EntityManager $entityManager Manager providing entity metadata
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->nodeReplacer = new AstNodeReplacer();
			$this->analyzer = new RangeUsageAnalyzer($this->entityStore); // single instance
		}
		
		/**
		 * Main optimization entry point - analyzes all ranges in the AST
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			foreach ($this->getAllAnyNodes($ast) as $node) {
				switch ($ast->getLocationOfChild($node)) {
					case 'select' :
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_CASE_WHEN);
						break;
					
					case 'conditions' :
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_EXISTS);
						break;
					
					case 'order_by' :
						break;
				}
			}
		}
		
		private function optimizeAnyNode(AstRetrieve $ast, AstAny $node, string $subQueryType): void {
			$cloned = $this->cloneRanges($ast);
			
			// one pass for all booleans you need:
			$usage = $this->analyzer->analyze($node, $cloned);
			$usedInExpr = $usage['usedInExpr'];
			$usedInCond = $usage['usedInCond'];
			$hasIsNull = $usage['hasIsNullInCond'];
			$nonNullUse = $usage['nonNullableUse'];
			
			// compute join refs once (no throw-away visitors)
			$usedInJoinOf = $this->computeJoinReferences($cloned);
			
			// decide live / correlateOnly
			$live = [];
			$corrOnly = [];
			$byName = [];
			foreach ($cloned as $r) $byName[$r->getName()] = $r;
			
			foreach ($cloned as $r) {
				$n = $r->getName();
				if (($usedInExpr[$n] ?? false) || ($usedInCond[$n] ?? false)) {
					$live[$n] = $r;
					continue;
				}
				$onlyViaJoin = false;
				foreach ($cloned as $k) {
					if (!empty($usedInJoinOf[$k->getName()][$n])) {
						$onlyViaJoin = true;
						break;
					}
				}
				if ($onlyViaJoin) {
					$corrOnly[$n] = $r;
				}
			}
			
			if (empty($live) && !empty($cloned)) {
				// fall back to expr range or first range
				$exprIds = $this->getIdentifiers($node->getIdentifier());
				foreach ($exprIds as $id) {
					$live[$id->getRange()->getName()] = $byName[$id->getRange()->getName()] ?? null;
				}
				if (empty($live)) {
					$live[$cloned[0]->getName()] = $cloned[0];
				}
			}
			
			// correlation promotion (split join predicates)
			$innerNames = array_keys($live);
			$corrNames = array_keys($corrOnly);
			$corrPreds = [];
			
			foreach ($live as $kName => $kRange) {
				$join = $kRange->getJoinProperty();
				if ($join === null) {
					continue;
				}
				
				$split = $this->splitPredicateByRangeRefs($join, $innerNames, $corrNames);
				$kRange->setJoinProperty($split['innerPart']);
				if ($split['corrPart'] !== null) {
					$corrPreds[] = $split['corrPart'];
				}
			}
			
			$conditions = $this->andAll([$node->getConditions(), $this->andAll($corrPreds)]);
			
			// keep order but only live
			$kept = [];
			foreach ($cloned as $r) if (isset($live[$r->getName()])) {
				$kept[] = $r;
			}
			
			// ensure anchor using the usage maps (no throw-away visitors)
			$kept = $this->ensureAnchorRangeNoVisitors($kept, $node, $conditions, $usedInExpr, $usedInCond, $hasIsNull, $nonNullUse);
			
			$subQuery = new AstSubquery($subQueryType, null, $kept, $conditions);
			$this->nodeReplacer->replaceChild($node->getParent(), $node, $subQuery);
		}
		
		/**
		 * Anchor selection without per-call visitor objects.
		 */
		private function ensureAnchorRangeNoVisitors(
			array         $ranges,
			AstAny        $any,
			?AstInterface &$where,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			if (empty($ranges)) {
				return $ranges;
			}
			
			// A) already anchored?
			foreach ($ranges as $r) if ($r->getJoinProperty() === null) {
				return $ranges;
			}
			
			$exprIds = $this->getIdentifiers($any->getIdentifier());
			$exprNames = array_map(fn($id) => $id->getRange()->getName(), $exprIds);
			
			$makeAnchor = function (int $idx) use (&$ranges, &$where) {
				$r = $ranges[$idx];
				$join = $r->getJoinProperty();
				if ($join !== null) {
					$where = $this->andAll([$where, $join]);
					$r->setJoinProperty(null);
				}
				if ($idx !== 0) {
					array_splice($ranges, $idx, 1);
					array_unshift($ranges, $r);
				}
				return $ranges;
			};
			
			$canCollapse = function (AstRange $r) use ($usedInExpr, $usedInCond, $hasIsNullInCond, $nonNullableUse) {
				$n = $r->getName();
				if ($usedInExpr[$n] ?? false) {
					return !($hasIsNullInCond[$n] ?? false);
				}
				if ($usedInCond[$n] ?? false) {
					return !($hasIsNullInCond[$n] ?? false);
				}
				if ($nonNullableUse[$n] ?? false) {
					return !($hasIsNullInCond[$n] ?? false);
				}
				return false;
			};
			
			// B) prefer expr range, collapsing if safe
			foreach ($ranges as $i => $r) {
				if (in_array($r->getName(), $exprNames, true)) {
					if ($r->isRequired() || (!$r->isRequired() && $canCollapse($r))) {
						if (!$r->isRequired() && $canCollapse($r)) {
							$r->setRequired(true);
						}
						return $makeAnchor($i);
					}
				}
			}
			
			// C) any INNER
			foreach ($ranges as $i => $r) if ($r->isRequired()) {
				return $makeAnchor($i);
			}
			
			// D) any LEFT that collapses safely
			foreach ($ranges as $i => $r) {
				if (!$r->isRequired() && $canCollapse($r)) {
					$r->setRequired(true);
					return $makeAnchor($i);
				}
			}
			
			// E) give up gracefully
			return $ranges;
		}
		
		private function computeJoinReferences(array $ranges): array {
			$usedInJoinOf = []; // [KName][RName] = true
			
			foreach ($ranges as $k) {
				$kName = $k->getName();
				$join = $k->getJoinProperty();
				if ($join === null) {
					continue;
				}
				
				$ids = $this->getIdentifiers($join);
				foreach ($ids as $id) {
					$rName = $id->getRange()->getName();
					if ($rName === $kName) {
						continue;
					} // self-ref is "inner", not correlation
					$usedInJoinOf[$kName][$rName] = true;
				}
			}
			return $usedInJoinOf;
		}
		
		/**
		 * @param AstInterface|null $predicate
		 * @param array $innerNames
		 * @param array $corrNames
		 * @return array{innerPart:?AstInterface, corrPart:?AstInterface}
		 */
		private function splitPredicateByRangeRefs(
			?AstInterface $predicate,
			array         $innerNames,
			array         $corrNames
		): array {
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			if ($this->isAndNode($predicate)) {
				$queue = [$predicate];
				$andLeaves = [];
				
				// Flatten AND tree so we can classify each conjunct independently
				while ($queue) {
					/** @var AstInterface $n */
					$n = array_pop($queue);
					if ($this->isAndNode($n)) {
						$queue[] = $n->getLeft();
						$queue[] = $n->getRight();
					} else {
						$andLeaves[] = $n;
					}
				}
				
				$innerParts = [];
				$corrParts = [];
				
				foreach ($andLeaves as $leaf) {
					$bucket = $this->classifyByRefs($leaf, $innerNames, $corrNames);
					if ($bucket === 'MIXED_OR_COMPLEX') {
						// Unsafe to split: keep everything as inner join
						return ['innerPart' => $predicate, 'corrPart' => null];
					}
					if ($bucket === 'CORR') {
						$corrParts[] = $leaf;
					} else { // 'INNER'
						$innerParts[] = $leaf;
					}
				}
				
				return [
					'innerPart' => $this->andAll($innerParts),
					'corrPart'  => $this->andAll($corrParts),
				];
			}
			
			// Non-AND predicate: classify the whole thing
			$bucket = $this->classifyByRefs($predicate, $innerNames, $corrNames);
			
			if ($bucket === 'MIXED_OR_COMPLEX') {
				return ['innerPart' => $predicate, 'corrPart' => null];
			}
			
			if ($bucket === 'CORR') {
				return ['innerPart' => null, 'corrPart' => $predicate];
			}
			
			return ['innerPart' => $predicate, 'corrPart' => null];
		}
		
		private function classifyByRefs(AstInterface $expr, array $innerNames, array $corrNames): string {
			$ids = $this->getIdentifiers($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$n = $id->getRange()->getName();
				if (in_array($n, $innerNames, true)) {
					$hasInner = true;
				}
				if (in_array($n, $corrNames, true)) {
					$hasCorr = true;
				}
			}
			
			if ($this->containsOr($expr) && $hasInner && $hasCorr) {
				return 'MIXED_OR_COMPLEX';
			}
			if ($hasCorr && !$hasInner) {
				return 'CORR';
			}
			return 'INNER';
		}
		
		private function containsOr(AstInterface $node): bool {
			if ($this->isOrNode($node)) {
				return true;
			}
			
			foreach ($this->childrenOf($node) as $child) {
				if ($this->containsOr($child)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Ensure exactly one anchor range (a range with null joinProperty) exists.
		 * Prefers the range used in ANY(expr), then any INNER, then a LEFT that is safe to collapse.
		 *
		 * @param AstRange[] $ranges Live ranges in desired order
		 * @param AstAny $anyNode The ANY(...) node (for liveness checks)
		 * @param AstInterface|null $where Subquery WHERE (will be updated)
		 * @return AstRange[]
		 */
		private function ensureAnchorRange(array $ranges, AstAny $anyNode, ?AstInterface &$where): array {
			if (empty($ranges)) {
				return $ranges;
			}
			
			// A) If an anchor already exists, nothing to do.
			foreach ($ranges as $r) {
				if ($r->getJoinProperty() === null) {
					return $ranges;
				}
			}
			
			// Helper to apply anchoring: move ON -> WHERE, clear join, move to front.
			$makeAnchor = function (int $idx) use (&$ranges, &$where) {
				$r = $ranges[$idx];
				$join = $r->getJoinProperty();
				if ($join !== null) {
					$where = $this->andAll([$where, $join]);
					$r->setJoinProperty(null);
				}
				if ($idx !== 0) {
					array_splice($ranges, $idx, 1);
					array_unshift($ranges, $r);
				}
				return $ranges;
			};
			
			// B) Prefer the range referenced in ANY(expr) as anchor.
			$exprIds = $this->getIdentifiers($anyNode->getIdentifier());
			$exprNames = array_map(fn($id) => $id->getRange()->getName(), $exprIds);
			foreach ($ranges as $i => $r) {
				if (in_array($r->getName(), $exprNames, true)) {
					// Safe to anchor on the expr range:
					// - If INNER: ON == WHERE
					// - If LEFT but collapsible in ANY(): flip to required and anchor.
					if ($r->isRequired() === false && $this->canCollapseLeftToInnerForAny($r, $anyNode)) {
						$r->setRequired(true); // LEFT -> INNER
					}
					if ($r->isRequired() === true) {
						return $makeAnchor($i);
					}
					// If still not safe, fall through to other candidates.
				}
			}
			
			// C) Any INNER range can be an anchor (ON == WHERE for INNER).
			foreach ($ranges as $i => $r) {
				if ($r->isRequired() === true) {
					return $makeAnchor($i);
				}
			}
			
			// D) As a fallback, pick a LEFT that is safe to collapse in this ANY() context.
			foreach ($ranges as $i => $r) {
				if ($r->isRequired() === false && $this->canCollapseLeftToInnerForAny($r, $anyNode)) {
					$r->setRequired(true); // collapse
					return $makeAnchor($i);
				}
			}
			
			// E) No safe anchor transformation found; leave as-is (caller may raise or handle later).
			// It's better to keep semantics than force a wrong rewrite.
			return $ranges;
		}
		
		/**
		 * Decide if a LEFT join can be treated as INNER in this ANY() subquery.
		 * Plug your real logic here using:
		 * - ContainsRange (used in expr/cond?)
		 * - ContainsNonNullableFieldForRange (non-nullable usage)
		 * - ContainsCheckIsNullForRange (IS NULL dependence)
		 */
		private function canCollapseLeftToInnerForAny(AstRange $range, AstAny $anyNode): bool {
			// Heuristic (tighten with your visitors):
			// 1) If the range is referenced in ANY(expr) or ANY(WHERE ...), then rows with NULL on this range
			//    cannot satisfy EXISTS -> LEFT behaves like INNER.
			$name = $range->getName();
			$usedInExpr = $this->containsRange($anyNode->getIdentifier(), $range);
			$usedInCond = $this->containsRange($anyNode->getConditions(), $range);
			if ($usedInExpr || $usedInCond) {
				// Also ensure we are not relying on IS NULL checks of this range inside the ANY() WHERE.
				$hasIsNull = (new ContainsCheckIsNullForRange($range))->check($anyNode->getConditions());
				return !$hasIsNull;
			}
			
			// 2) Or all references to this range inside ANY() are on non-nullable fields.
			$nonNullableUse = (new ContainsNonNullableFieldForRange($range, $this->entityStore))
				->check($anyNode);
			if ($nonNullableUse) {
				$hasIsNull = (new ContainsCheckIsNullForRange($range))->check($anyNode->getConditions());
				return !$hasIsNull;
			}
			
			return false;
		}
		
		private function isInnerJoin(AstRange $r): bool {
			// INNER when required
			return $r->isRequired() === true;
		}
		
		private function isLeftJoin(AstRange $r): bool {
			// LEFT/optional when not required
			return $r->isRequired() === false;
		}
		
		
		/**
		 * Conservative: LEFT->INNER is safe if:
		 *  - right side is referenced in ANY(expr) or ANY(WHERE ...), or
		 *  - only non-nullable fields of the right side are referenced, and
		 *  - there is no IS NULL check on the right side in this subquery.
		 * Refine using your visitors (ContainsNonNullableFieldForRange, ContainsCheckIsNullForRange, etc.).
		 */
		private function canCollapseLeftToInner(AstRange $leftRange): bool {
			// If you track "the other side" of this join explicitly, consult it here; otherwise, rely on global checks.
			$rangeName = $leftRange->getName();
			
			// (1) If the right side is referenced in expr/cond, rows with NULL right side wouldn't pass.
			// You can wire this up by passing in the current ANY() node or memoized liveness maps.
			// For simplicity, return false here; plug your real checks in production.
			return false;
		}
		
		/**
		 * Build an AND tree from a list of predicates.
		 * - []      -> null
		 * - [a]     -> a
		 * - [a,b,c] -> ((a AND b) AND c)
		 *
		 * @param AstInterface[] $parts
		 * @return AstInterface|null
		 */
		private function andAll(array $parts): ?AstInterface {
			// Remove nulls/empties
			$parts = array_values(array_filter($parts));
			$n = count($parts);
			if ($n === 0) {
				return null;
			}
			if ($n === 1) {
				return $parts[0];
			}
			
			// Left-associative chain is fine; if you care, you can balance it
			$acc = new AstBinaryOperator($parts[0], $parts[1], 'AND');
			
			for ($i = 2; $i < $n; $i++) {
				$acc = new AstBinaryOperator($acc, $parts[$i], 'AND');
			}
			
			return $acc;
		}
		
		// Helper predicates depending on your AST types:
		private function isAndNode(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'AND';
		}
		
		private function isOrNode(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'OR';
		}
		
		/** @return AstInterface[] */
		private function childrenOf(AstInterface $node): array {
			if ($node instanceof AstBinaryOperator) {
				return [$node->getLeft(), $node->getRight()];
			}
			return [];
		}
		
		
		/**
		 * Filter out unused joins
		 * @param array $ranges
		 * @param AstAny $node
		 * @return array
		 */
		private function filterRanges(array $ranges, AstAny $node): array {
			$result = [];
			
			foreach ($ranges as $range) {
				// Check if range is referenced in ANY(expr) or conditions
				$usedInExpr = $this->containsRange($node->getIdentifier(), $range);
				$usedInCond = $this->containsRange($node->getConditions(), $range);
				
				// If not used at all drop it
				if (!$usedInExpr && !$usedInCond) {
					continue;
				}
				
				// Add range to list
				$result[] = $range;
			}
			
			return $result;
		}
		
		/**
		 * Fetch list of all used identifiers
		 * @param AstInterface|null $ast
		 * @return array
		 */
		private function getIdentifiers(?AstInterface $ast): array {
			if ($ast === null) {
				return [];
			}
			
			$visitor = new CollectIdentifiers();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Returns true if any of the identifiers use the range
		 * @param AstInterface|null $ast
		 * @param AstRange $range
		 * @return bool
		 */
		private function containsRange(?AstInterface $ast, AstRange $range): bool {
			$identifiers = $this->getIdentifiers($ast);
			
			foreach ($identifiers as $identifier) {
				if ($identifier->getRange()->getName() === $range->getName()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Clones ranges
		 * @param AstRetrieve $ast
		 * @return array
		 */
		private function cloneRanges(AstRetrieve $ast): array {
			$result = [];
			
			foreach ($ast->getRanges() as $range) {
				$result[] = $range->deepClone();
			}
			
			return $result;
		}
		
		/**
		 * Fetch a list of ANY nodes
		 * @param AstRetrieve $ast
		 * @return array
		 */
		private function getAllAnyNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes([AstAny::class]);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
	}