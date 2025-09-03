<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	
	/**
	 * Class AggregateOptimizer
	 *
	 * Optimizes aggregate expressions in an ObjectQuel retrieve AST by choosing one of
	 * three strategies per aggregate:
	 *  - DIRECT   : keep in the outer query (add GROUP BY if needed)
	 *  - SUBQUERY : compute in a correlated scalar subquery over the minimal ranges
	 *  - WINDOW   : compute via window function (when DB + query shape permit)
	 *
	 * Primary goal is to reduce join width and isolate heavy work without changing semantics.
	 */
	class AggregateOptimizer {
		/** Strategy label: keep aggregate in the main query. */
		private const STRATEGY_DIRECT = 'DIRECT';
		/** Strategy label: compute aggregate in a correlated subquery. */
		private const STRATEGY_SUBQUERY = 'SUBQUERY';
		/** Strategy label: compute aggregate as a window function. */
		private const STRATEGY_WINDOW = 'WINDOW';
		
		private EntityManager $entityManager;
		private AstUtilities $astUtils;
		private AstNodeReplacer $replacer;
		
		/** @var array<class-string<AstInterface>> Supported aggregate node classes. */
		private array $aggregateNodeTypes = [
			AstSum::class,
			AstSumU::class,
			AstCount::class,
			AstCountU::class,
			AstAvg::class,
			AstAvgU::class,
			AstMin::class,
			AstMax::class,
		];
		
		/** @var array<class-string<AstInterface>> DISTINCT-capable aggregate classes. */
		private array $distinctAggregateTypes = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class,
		];
		
		/**
		 * @param EntityManager $entityManager Provides DB capabilities (e.g. window support)
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->astUtils = new AstUtilities();
			$this->replacer = new AstNodeReplacer();
		}
		
		// ---------------------------------------------------------------------
		// Public API
		// ---------------------------------------------------------------------
		
		/**
		 * Optimize the provided retrieve AST in-place.
		 *
		 * Pipeline:
		 *  1) Remove unused ranges when SELECT is aggregates-only
		 *  2) Rewrite filter-only joins to EXISTS
		 *  3) Simplify trivial self-join EXISTS to NOT NULL checks
		 *  4) Per aggregate: choose DIRECT/SUBQUERY/WINDOW and rewrite
		 *
		 * @param AstRetrieve $root Root query node to mutate
		 * @return void
		 */
		public function optimize(AstRetrieve $root): void {
			$this->removeUnusedRangesInAggregateOnlyQueries($root);
			$this->rewriteFilterOnlyJoinsAsExists($root);
			$this->simplifySelfJoinExists($root, /* includeNulls */ false);
			
			$aggregates = $this->collectAggregateNodes($root);
			foreach ($aggregates as $agg) {
				$strategy = $this->chooseStrategy($root, $agg);
				
				switch ($strategy) {
					case self::STRATEGY_DIRECT:
						if ($this->selectNeedsGroupBy($root)) {
							$this->ensureGroupByForNonAggregates($root);
						}
						break;
					
					case self::STRATEGY_SUBQUERY:
						$this->rewriteAggregateAsCorrelatedSubquery($root, $agg);
						break;
					
					case self::STRATEGY_WINDOW:
						$this->rewriteAggregateAsWindowFunction($agg);
						break;
				}
			}
		}
		
		// ---------------------------------------------------------------------
		// Strategy selection
		// ---------------------------------------------------------------------
		
		/**
		 * Pick the best evaluation strategy for a given aggregate within the query.
		 *
		 * Rules (priority):
		 *  1) Aggregate has conditions → SUBQUERY
		 *  2) SELECT is aggregate-only  → DIRECT
		 *  3) Mixed SELECT: if ranges overlap/relate → DIRECT, else SUBQUERY
		 *  4) Single-table + DB supports + shape fits → WINDOW
		 *  5) Fallback → SUBQUERY
		 *
		 * @param AstRetrieve $root Query AST
		 * @param AstInterface $aggregate Aggregate node to analyze
		 * @return string One of self::STRATEGY_* constants
		 */
		private function chooseStrategy(AstRetrieve $root, AstInterface $aggregate): string {
			if ($aggregate->getConditions() !== null) {
				return self::STRATEGY_SUBQUERY;
			}
			
			if ($this->selectIsAggregateOnly($root)) {
				return self::STRATEGY_DIRECT;
			}
			
			$nonAggItems = $this->collectNonAggregateSelectItems($root);
			if (!empty($nonAggItems)) {
				$aggRanges = $this->collectRangesFromNode($aggregate);
				$nonAggRanges = $this->collectRangesFromNodes($nonAggItems);
				
				if ($this->rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)) {
					return self::STRATEGY_DIRECT;
				}
				return self::STRATEGY_SUBQUERY;
			}
			
			if ($this->canRewriteAsWindowFunction($root, $aggregate)) {
				return self::STRATEGY_WINDOW;
			}
			
			return self::STRATEGY_SUBQUERY;
		}
		
		// ---------------------------------------------------------------------
		// Rewriters
		// ---------------------------------------------------------------------
		
		/**
		 * Replace an aggregate node with a correlated scalar subquery that computes it
		 * over the minimal set of ranges it depends on.
		 *
		 * @param AstRetrieve $root Outer query AST
		 * @param AstInterface $aggregate Aggregate node to replace
		 * @return void
		 */
		private function rewriteAggregateAsCorrelatedSubquery(AstRetrieve $root, AstInterface $aggregate): void {
			$aggRanges = $this->collectRangesFromNode($aggregate);
			$outerRanges = $root->getRanges();
			
			$neededRanges = $this->computeMinimalRangeSet($outerRanges, $aggRanges);
			$clonedRanges = array_map(static fn(AstRange $r) => $r->deepClone(), $neededRanges);
			
			$subWhere = $aggregate->getConditions();
			$cleanAgg = $this->cloneAggregateWithoutConditions($aggregate);
			
			$subquery = new AstSubquery(
				AstSubquery::TYPE_SCALAR,
				$cleanAgg,
				$clonedRanges,
				$subWhere
			);
			
			$this->replacer->replaceChild($aggregate->getParent(), $aggregate, $subquery);
		}
		
		/**
		 * Replace an aggregate node with a window-function node.
		 *
		 * @param AstInterface $aggregate Aggregate node to replace
		 * @return void
		 */
		private function rewriteAggregateAsWindowFunction(AstInterface $aggregate): void {
			$cleanAgg = $this->cloneAggregateWithoutConditions($aggregate);
			
			$windowFn = new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$cleanAgg,
				/* ranges */ [],
				/* where  */ null
			);
			
			$this->replacer->replaceChild($aggregate->getParent(), $aggregate, $windowFn);
		}
		
		// ---------------------------------------------------------------------
		// Helpers: cloning / ranges
		// ---------------------------------------------------------------------
		
		/**
		 * Deep-clone an aggregate and drop embedded conditions.
		 *
		 * @param AstInterface $aggregate Aggregate to clone
		 * @return AstInterface Clean clone without conditions
		 */
		private function cloneAggregateWithoutConditions(AstInterface $aggregate): AstInterface {
			$clone = $aggregate->deepClone();
			$clone->setConditions(null);
			return $clone;
		}
		
		/**
		 * Compute minimal range set (seed ranges + join dependency closure).
		 *
		 * @param AstRange[] $allRanges All ranges in the outer query
		 * @param AstRange[] $seedRanges Ranges referenced by the aggregate
		 * @return AstRange[] Minimal set of ranges needed for correctness
		 */
		private function computeMinimalRangeSet(array $allRanges, array $seedRanges): array {
			$required = [];
			$processed = [];
			
			foreach ($seedRanges as $seed) {
				$this->expandWithJoinDependencies($seed, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Recursively add a range and the ranges referenced by its join predicate.
		 *
		 * @param AstRange $range Starting range
		 * @param AstRange[] $universe All known ranges
		 * @param array<int,AstRange> $required Collected set (by reference)
		 * @param array<int,AstRange> $processed DFS guard
		 * @return void
		 */
		private function expandWithJoinDependencies(AstRange $range, array $universe, array &$required, array &$processed): void {
			if (in_array($range, $processed, true)) {
				return; // avoid cycles
			}
			
			$processed[] = $range;
			
			if (!in_array($range, $required, true)) {
				$required[] = $range;
			}
			
			$joinPredicate = $range->getJoinProperty();
			if ($joinPredicate === null) {
				return;
			}
			
			$referenced = $this->collectRangesFromNode($joinPredicate);
			foreach ($referenced as $ref) {
				if ($ref !== $range) {
					$this->expandWithJoinDependencies($ref, $universe, $required, $processed);
				}
			}
		}
		
		// ---------------------------------------------------------------------
		// Helpers: selection analysis
		// ---------------------------------------------------------------------
		
		/**
		 * @param AstRetrieve $root Query to visit
		 * @return AstInterface[] Aggregate nodes found in the tree
		 */
		private function collectAggregateNodes(AstRetrieve $root): array {
			$visitor = new CollectNodes($this->aggregateNodeTypes);
			$root->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param AstRetrieve $root Query node
		 * @return array<int,mixed> Non-aggregate SELECT value nodes
		 */
		private function collectNonAggregateSelectItems(AstRetrieve $root): array {
			$result = [];
			foreach ($root->getValues() as $selectItem) {
				$expr = $selectItem->getExpression();
				if (!$this->isAggregateExpression($expr)) {
					$result[] = $selectItem;
				}
			}
			return $result;
		}
		
		/**
		 * @param AstRetrieve $root
		 * @return bool True if every SELECT expression is an aggregate
		 */
		private function selectIsAggregateOnly(AstRetrieve $root): bool {
			foreach ($root->getValues() as $selectItem) {
				if (!$this->isAggregateExpression($selectItem->getExpression())) {
					return false;
				}
			}
			return true;
		}
		
		/**
		 * @param AstInterface $expr
		 * @return bool True if $expr is one of the supported aggregate classes
		 */
		private function isAggregateExpression(AstInterface $expr): bool {
			return in_array(get_class($expr), $this->aggregateNodeTypes, true);
		}
		
		/**
		 * @param AstInterface $node Node to inspect
		 * @return AstRange[] Ranges referenced by the node
		 */
		private function collectRangesFromNode(AstInterface $node): array {
			$visitor = new CollectRanges();
			$node->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param array<int,mixed> $nodes Value/Expression nodes
		 * @return AstRange[] Ranges referenced by all nodes
		 */
		private function collectRangesFromNodes(array $nodes): array {
			$visitor = new CollectRanges();
			foreach ($nodes as $n) {
				if ($n instanceof AstInterface) {
					$n->accept($visitor);
				} elseif (method_exists($n, 'getExpression')) {
					$expr = $n->getExpression();
					if ($expr instanceof AstInterface) {
						$expr->accept($visitor);
					}
				}
			}
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param AstRange[] $setA
		 * @param AstRange[] $setB
		 * @return bool True if sets overlap or are joined transitively
		 */
		private function rangesOverlapOrAreRelated(array $setA, array $setB): bool {
			foreach ($setA as $a) {
				foreach ($setB as $b) {
					if ($a === $b) {
						return true; // direct overlap
					}
				}
			}
			return $this->rangesAreRelatedViaJoins($setA, $setB);
		}
		
		/**
		 * @param AstRange[] $setA
		 * @param AstRange[] $setB
		 * @return bool True if any pair (a,b) is joined
		 */
		private function rangesAreRelatedViaJoins(array $setA, array $setB): bool {
			foreach ($setA as $a) {
				foreach ($setB as $b) {
					if ($this->rangesAreJoined($a, $b)) {
						return true;
					}
				}
			}
			return false;
		}
		
		/**
		 * @param AstRange $a
		 * @param AstRange $b
		 * @return bool True if ranges reference each other in a join predicate
		 */
		private function rangesAreJoined(AstRange $a, AstRange $b): bool {
			$bJoin = $b->getJoinProperty();
			if ($bJoin && $this->joinPredicateReferencesRange($bJoin, $a)) {
				return true;
			}
			$aJoin = $a->getJoinProperty();
			if ($aJoin && $this->joinPredicateReferencesRange($aJoin, $b)) {
				return true;
			}
			return false;
		}
		
		/**
		 * @param AstInterface $joinPredicate Join expression
		 * @param AstRange $target Range to look for
		 * @return bool True if any identifier in the predicate belongs to $target
		 */
		private function joinPredicateReferencesRange(AstInterface $joinPredicate, AstRange $target): bool {
			$ids = $this->astUtils->collectIdentifiersFromAst($joinPredicate);
			foreach ($ids as $id) {
				if ($id->getRange() === $target) {
					return true;
				}
			}
			return false;
		}
		
		// ---------------------------------------------------------------------
		// GROUP BY management
		// ---------------------------------------------------------------------
		
		/**
		 * @param AstRetrieve $root
		 * @return bool True if SELECT mixes aggregate and non-aggregate expressions
		 */
		private function selectNeedsGroupBy(AstRetrieve $root): bool {
			return !$this->selectIsAggregateOnly($root);
		}
		
		/**
		 * Ensure GROUP BY contains all non-aggregate SELECT items.
		 *
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		private function ensureGroupByForNonAggregates(AstRetrieve $root): void {
			$root->setGroupBy($this->collectNonAggregateSelectItems($root));
		}
		
		// ---------------------------------------------------------------------
		// Pre-optimizations
		// ---------------------------------------------------------------------
		
		/**
		 * Aggregate-only SELECT: remove FROM ranges not referenced by SELECT/WHERE
		 * or by join dependency closure. If a single range remains and its join
		 * predicate references only itself, fold predicate into WHERE and clear join.
		 *
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		private function removeUnusedRangesInAggregateOnlyQueries(AstRetrieve $root): void {
			if (!$this->selectIsAggregateOnly($root)) {
				return;
			}
			
			$allRanges = $root->getRanges();
			$usedInSelect = $this->collectRangesUsedInSelect($root);
			
			$outerWhere = $root->getConditions();
			$usedInWhere = $outerWhere ? $this->collectRangesFromNode($outerWhere) : [];
			
			$usedInJoins = [];
			foreach ($allRanges as $r) {
				$jp = $r->getJoinProperty();
				if ($jp !== null) {
					$usedInJoins = array_merge($usedInJoins, $this->collectRangesFromNode($jp));
				}
			}
			
			$keep = [];
			foreach (array_unique(array_merge($usedInSelect, $usedInWhere, $usedInJoins), SORT_REGULAR) as $r) {
				$keep[spl_object_hash($r)] = $r;
				$processed = [];
				$this->expandWithJoinDependencies($r, $allRanges, $keep, $processed);
			}
			
			foreach ($allRanges as $range) {
				if (!isset($keep[spl_object_hash($range)])) {
					$root->removeRange($range);
				}
			}
			
			if (count($root->getRanges()) === 1) {
				$only = $root->getRanges()[0];
				$jp = $only->getJoinProperty();
				if ($jp !== null) {
					$refs = $this->collectRangesFromNode($jp);
					$selfOnly = true;
					foreach ($refs as $ref) {
						if ($ref !== $only) {
							$selfOnly = false;
							break;
						}
					}
					
					if ($selfOnly) {
						$where = $root->getConditions();
						$root->setConditions($where ? new AstBinaryOperator($where, $jp, 'AND') : $jp);
						$only->setJoinProperty(null);
					}
				}
			}
		}
		
		/**
		 * When SELECT is aggregates-only, rewrite join edges that merely filter
		 * (and do not feed aggregates/WHERE) into EXISTS subqueries.
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		private function rewriteFilterOnlyJoinsAsExists(AstRetrieve $root): void {
			if (!$this->selectIsAggregateOnly($root)) {
				return;
			}
			
			$ranges = $root->getRanges();
			
			if (count($ranges) < 2) {
				return;
			}
			
			$aggRangeMap = $this->mapRangesUsedByAggregates($root);
			$outerWhere = $root->getConditions();
			$rangesInWhere = $outerWhere ? $this->collectRangesFromNode($outerWhere) : [];
			
			foreach ($ranges as $hostRange) {
				$hostJoin = $hostRange->getJoinProperty();
				if ($hostJoin === null) {
					continue;
				}
				
				$joinRefs = $this->collectRangesFromNode($hostJoin);
				foreach ($joinRefs as $refRange) {
					$refHash = spl_object_hash($refRange);
					$feedsAgg = isset($aggRangeMap[$refHash]);
					$feedsWhere = in_array($refRange, $rangesInWhere, true);
					if ($feedsAgg || $feedsWhere) {
						continue;
					}
					
					// Build EXISTS using a clone of the referenced range
					$clonedRef = $refRange->deepClone();
					$rebasedWhere = $this->rebindPredicateToClone($hostJoin, $refRange, $clonedRef);
					
					$exists = new AstSubquery(
						AstSubquery::TYPE_EXISTS,
						new AstNumber(1),
						[$clonedRef],
						$rebasedWhere
					);
					
					$outerWhere = $outerWhere ? new AstBinaryOperator($outerWhere, $exists, 'AND') : $exists;
					$root->setConditions($outerWhere);
					
					$hostRange->setJoinProperty(null);
					$root->removeRange($refRange);
				}
			}
		}
		
		/**
		 * @param AstRetrieve $root
		 * @return array<string,bool> map: spl_object_hash(AstRange) => true for ranges used by aggregates
		 */
		private function mapRangesUsedByAggregates(AstRetrieve $root): array {
			$map = [];
			
			foreach ($this->collectAggregateNodes($root) as $agg) {
				foreach ($this->collectRangesFromNode($agg) as $r) {
					$map[spl_object_hash($r)] = true;
				}
			}
			
			return $map;
		}
		
		/**
		 * Clone a predicate and retarget identifiers pointing to $oldRange so that they
		 * point to $newRange.
		 * @param AstInterface $predicate Predicate to clone
		 * @param AstRange $oldRange Original range
		 * @param AstRange $newRange Replacement range
		 * @return AstInterface Cloned predicate with identifiers rebound
		 */
		private function rebindPredicateToClone(AstInterface $predicate, AstRange $oldRange, AstRange $newRange): AstInterface {
			$cloned = $predicate->deepClone();
			
			$visitor = new CollectIdentifiers();
			$cloned->accept($visitor);
			$ids = $visitor->getCollectedNodes();
			
			foreach ($ids as $id) {
				if ($id->getRange() === $oldRange) {
					$id->setRange($newRange);
				}
			}
			
			return $cloned;
		}
		
		// ---------------------------------------------------------------------
		// EXISTS simplification for trivial self-joins
		// ---------------------------------------------------------------------
		
		/**
		 * Replace EXISTS(SelfJoin) with NOT NULL checks on the outer side (or TRUE if nulls allowed).
		 * @param AstRetrieve $root Query to mutate
		 * @param bool $includeNulls If true, EXISTS collapses to TRUE
		 * @return void
		 */
		private function simplifySelfJoinExists(AstRetrieve $root, bool $includeNulls): void {
			$where = $root->getConditions();
			if ($where === null) {
				return;
			}
			
			$rewritten = $this->rewriteExistsNodesWithinAndTree($where, $includeNulls);
			if ($rewritten !== $where) {
				$root->setConditions($rewritten);
			}
		}
		
		/**
		 * Recursively traverse an AND-tree and simplify eligible EXISTS nodes.
		 *
		 * @param AstInterface $node Root of the subtree
		 * @param bool $includeNulls If true, replace with TRUE predicate
		 * @return AstInterface Possibly rewritten node
		 */
		private function rewriteExistsNodesWithinAndTree(AstInterface $node, bool $includeNulls): AstInterface {
			if ($node instanceof AstBinaryOperator && $node->getOperator() === 'AND') {
				$l = $this->rewriteExistsNodesWithinAndTree($node->getLeft(), $includeNulls);
				$r = $this->rewriteExistsNodesWithinAndTree($node->getRight(), $includeNulls);
				if ($l === $node->getLeft() && $r === $node->getRight()) {
					return $node;
				}
				return new AstBinaryOperator($l, $r, 'AND');
			}
			
			if ($node instanceof AstSubquery && $node->getType() === AstSubquery::TYPE_EXISTS) {
				$replacement = $this->simplifySingleExistsNodeIfSelfJoin($node, $includeNulls);
				if ($replacement !== null) {
					return $replacement;
				}
			}
			
			return $node;
		}
		
		/**
		 * Attempt to turn a single EXISTS(subquery) into a NOT NULL chain if it is a
		 * trivial self-join of the same entity on the same column(s).
		 *
		 * @param AstSubquery $existsNode EXISTS node to analyze
		 * @param bool $includeNulls If true, return TRUE predicate
		 * @return AstInterface|null Replacement node or null to keep original
		 */
		private function simplifySingleExistsNodeIfSelfJoin(AstSubquery $existsNode, bool $includeNulls): ?AstInterface {
			$innerRanges = $existsNode->getCorrelatedRanges();
			if (empty($innerRanges)) {
				return null;
			}
			$innerSet = [];
			foreach ($innerRanges as $ir) {
				$innerSet[spl_object_hash($ir)] = true;
			}
			
			$cond = $existsNode->getConditions();
			if ($cond === null) {
				return null;
			}
			
			$pairs = [];
			if (!$this->collectOuterInnerIdPairs($cond, $innerSet, $pairs) || empty($pairs)) {
				return null;
			}
			
			// Validate self-join semantics: same entity + same property on both sides.
			foreach ($pairs as [$outerId, $innerId]) {
				/** @var AstIdentifier $outerBase */
				$outerBase = $outerId->getBaseIdentifier();
				/** @var AstIdentifier $innerBase */
				$innerBase = $innerId->getBaseIdentifier();
				
				$outerRange = $outerBase->getRange();
				$innerRange = $innerBase->getRange();
				if ($outerRange === null || $innerRange === null) {
					return null;
				}
				if ($outerRange->getEntityName() !== $innerRange->getEntityName()) {
					return null;
				}
				
				$outerProp = method_exists($outerId, 'getPropertyName') ? $outerId->getPropertyName() : $outerId->getName();
				$innerProp = method_exists($innerId, 'getPropertyName') ? $innerId->getPropertyName() : $innerId->getName();
				if ($outerProp === '' || $innerProp === '' || $outerProp !== $innerProp) {
					return null;
				}
			}
			
			if ($includeNulls) {
				return new AstExpression(new AstNumber(1), new AstNumber(1), '=');
			}
			
			// Replace EXISTS with: outer.col IS NOT NULL AND ...
			$chain = null;
			
			foreach ($pairs as [$outerId, $_innerId]) {
				$pred = new AstCheckNotNull($outerId->deepClone());
				$chain = $chain ? new AstBinaryOperator($chain, $pred, 'AND') : $pred;
			}
			return $chain;
		}
		
		/**
		 * Collect pairs [outerId, innerId] for simple equality leaves (id = id)
		 * where exactly one side belongs to an inner range.
		 * @param AstInterface $expr Predicate subtree to scan
		 * @param array<string,bool> $innerSet map: spl_object_hash(AstRange) => true
		 * @param array<int,array{0:AstIdentifier,1:AstIdentifier}> $pairs Output pairs
		 * @return bool False if a non-eligible leaf is encountered
		 */
		private function collectOuterInnerIdPairs(AstInterface $expr, array $innerSet, array &$pairs): bool {
			if ($expr instanceof AstBinaryOperator && $expr->getOperator() === 'AND') {
				return $this->collectOuterInnerIdPairs($expr->getLeft(), $innerSet, $pairs)
					&& $this->collectOuterInnerIdPairs($expr->getRight(), $innerSet, $pairs);
			}
			
			if ($expr instanceof AstExpression && $expr->getOperator() === '=') {
				$L = $expr->getLeft();
				$R = $expr->getRight();
				
				if (!($L instanceof AstIdentifier) || !($R instanceof AstIdentifier)) {
					return false;
				}
				
				/** @var AstIdentifier $Lb */
				$Lb = $L->getBaseIdentifier();
				/** @var AstIdentifier $Rb */
				$Rb = $R->getBaseIdentifier();
				
				$Lr = $Lb->getRange();
				$Rr = $Rb->getRange();
				
				if ($Lr === null || $Rr === null) {
					return false;
				}
				
				$Linner = isset($innerSet[spl_object_hash($Lr)]);
				$Rinner = isset($innerSet[spl_object_hash($Rr)]);
				
				if ($Linner === $Rinner) { // both inner or both outer
					return false;
				}
				
				// Canonical order: [outerId, innerId]
				$pairs[] = $Linner ? [$R, $L] : [$L, $R];
				return true;
			}
			
			return false; // other node types are not eligible
		}
		
		// ---------------------------------------------------------------------
		// Misc helpers
		// ---------------------------------------------------------------------
		
		/**
		 * @param AstRetrieve $root
		 * @return AstRange[] Ranges referenced anywhere in SELECT
		 */
		private function collectRangesUsedInSelect(AstRetrieve $root): array {
			$collector = new CollectRanges();

			foreach ($root->getValues() as $value) {
				$value->accept($collector);
			}

			return $collector->getCollectedNodes();
		}
		
		/**
		 * Check if we can safely rewrite the aggregate to a window function.
		 * @param AstRetrieve $root Query root
		 * @param AstInterface $aggregate Aggregate under analysis
		 * @return bool True if rewrite is permitted
		 */
		private function canRewriteAsWindowFunction(AstRetrieve $root, AstInterface $aggregate): bool {
			if (!$this->passesWindowFunctionBasics($aggregate)) {
				return false;
			}
			
			$ranges = $root->getRanges();
			
			if (count($ranges) !== 1) {
				return false; // must be single-table
			}
			
			$aggRanges = $this->collectRangesFromNode($aggregate);
			
			if (count($aggRanges) !== 1 || $aggRanges[0] !== $ranges[0]) {
				return false; // aggregate must reference exactly that single range
			}
			
			foreach ($root->getValues() as $item) {
				if ($item->getExpression() === $aggregate) {
					continue; // skip the aggregate under analysis
				}

				$itemRanges = $this->collectRangesFromNode($item->getExpression());

				if (count($itemRanges) !== 1 || $itemRanges[0] !== $ranges[0]) {
					return false; // all items must reference the same single range
				}
			}
			
			return true;
		}
		
		/**
		 * Basic window support checks: no conditions, not DISTINCT variant, DB supports,
		 * and the aggregate type is allowed.
		 * @param AstInterface $aggregate Aggregate to test
		 * @return bool True if basic constraints are satisfied
		 */
		private function passesWindowFunctionBasics(AstInterface $aggregate): bool {
			if ($aggregate->getConditions() !== null) {
				return false; // window functions can't have their own filters
			}
			
			if (in_array(get_class($aggregate), $this->distinctAggregateTypes, true)) {
				return false; // DISTINCT variants commonly unsupported for window context
			}
			
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false; // database capability
			}
			
			return $this->isSupportedWindowAggregate($aggregate);
		}
		
		/**
		 * @param AstInterface $aggregate
		 * @return bool True if the aggregate is safe for window context
		 */
		private function isSupportedWindowAggregate(AstInterface $aggregate): bool {
			$supported = [
				AstSum::class,
				AstCount::class,
				AstAvg::class,
				AstMin::class,
				AstMax::class,
			];
			return in_array(get_class($aggregate), $supported, true);
		}
	}