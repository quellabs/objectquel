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
		private const string STRATEGY_DIRECT = 'DIRECT';

		/** Strategy label: compute aggregate in a correlated subquery. */
		private const string STRATEGY_SUBQUERY = 'SUBQUERY';

		/** Strategy label: compute aggregate as a window function. */
		private const string STRATEGY_WINDOW = 'WINDOW';
		
		private EntityManager $entityManager;
		private Support\AstUtilities $astUtils;
		private Support\AstNodeReplacer $replacer;
		
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
			$this->astUtils = new Support\AstUtilities();
			$this->replacer = new Support\AstNodeReplacer();
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
			
			foreach ($this->collectAggregateNodes($root) as $agg) {
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
				} else {
					return self::STRATEGY_SUBQUERY;
				}
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
				} else {
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
		 * Removes unused FROM ranges in aggregate-only SELECT queries.
		 *
		 * Algorithm:
		 * 1. Find ranges referenced in SELECT, WHERE, or JOIN predicates
		 * 2. Expand the set with join dependencies (transitively)
		 * 3. Remove any ranges not in the final set
		 * 4. Optimize single-range case: fold self-referencing joins into WHERE
		 *
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		private function removeUnusedRangesInAggregateOnlyQueries(AstRetrieve $root): void {
			if (!$this->selectIsAggregateOnly($root)) {
				return;
			}
			
			$allRanges = $root->getRanges();
			
			// Collect all directly referenced ranges
			$directlyUsed = $this->collectDirectlyUsedRanges($root, $allRanges);
			
			// Expand to include join dependencies
			$requiredRanges = $this->expandWithAllJoinDependencies($directlyUsed, $allRanges);
			
			// Remove unused ranges
			$this->removeRangesNotInSet($root, $allRanges, $requiredRanges);
			
			// Optimize single-range case
			$this->optimizeSingleRangeQuery($root);
		}
		
		/**
		 * Collects ranges directly referenced in SELECT, WHERE, and JOIN predicates.
		 */
		private function collectDirectlyUsedRanges(AstRetrieve $root, array $allRanges): array {
			$used = [];
			
			// Ranges used in SELECT clause
			$used = array_merge($used, $this->collectRangesUsedInSelect($root));
			
			// Ranges used in WHERE clause
			$outerWhere = $root->getConditions();
			if ($outerWhere) {
				$used = array_merge($used, $this->collectRangesFromNode($outerWhere));
			}
			
			// Ranges used in JOIN predicates
			foreach ($allRanges as $range) {
				$joinPredicate = $range->getJoinProperty();
				if ($joinPredicate) {
					$used = array_merge($used, $this->collectRangesFromNode($joinPredicate));
				}
			}
			
			return array_unique($used, SORT_REGULAR);
		}
		
		/**
		 * Expands the set of ranges to include all join dependencies transitively.
		 */
		private function expandWithAllJoinDependencies(array $directlyUsed, array $allRanges): array {
			$required = [];
			$processed = [];
			
			foreach ($directlyUsed as $range) {
				$required[spl_object_hash($range)] = $range;
				$this->expandWithJoinDependencies($range, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Removes ranges that are not in the required set.
		 */
		private function removeRangesNotInSet(AstRetrieve $root, array $allRanges, array $requiredRanges): void {
			foreach ($allRanges as $range) {
				if (!isset($requiredRanges[spl_object_hash($range)])) {
					$root->removeRange($range);
				}
			}
		}
		
		/**
		 * Optimizes queries with a single range by folding self-referencing joins into WHERE.
		 */
		private function optimizeSingleRangeQuery(AstRetrieve $root): void {
			$remainingRanges = $root->getRanges();
			
			if (count($remainingRanges) !== 1) {
				return;
			}
			
			$singleRange = $remainingRanges[0];
			$joinPredicate = $singleRange->getJoinProperty();
			
			if (!$joinPredicate) {
				return;
			}
			
			// Check if join predicate only references the single range
			if ($this->joinPredicateReferencesOnlySelf($joinPredicate, $singleRange)) {
				$this->foldJoinPredicateIntoWhere($root, $joinPredicate);
				$singleRange->setJoinProperty(null);
			}
		}
		
		/**
		 * Checks if a join predicate only references the given range.
		 * @param $joinPredicate
		 * @param $targetRange
		 * @return bool
		 */
		private function joinPredicateReferencesOnlySelf($joinPredicate, $targetRange): bool {
			$referencedRanges = $this->collectRangesFromNode($joinPredicate);
			
			foreach ($referencedRanges as $range) {
				if ($range !== $targetRange) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Folds a join predicate into the WHERE clause using AND.
		 * @param AstRetrieve $root
		 * @param $joinPredicate
		 * @return void
		 */
		private function foldJoinPredicateIntoWhere(AstRetrieve $root, $joinPredicate): void {
			$existingWhere = $root->getConditions();
			
			if ($existingWhere) {
				$newWhere = new AstBinaryOperator($existingWhere, $joinPredicate, 'AND');
			} else {
				$newWhere = $joinPredicate;
			}
			
			$root->setConditions($newWhere);
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
		 * This function performs a depth-first traversal of an AST subtree rooted at an AND operator,
		 * looking for EXISTS subqueries that can be optimized. The optimization typically converts
		 * self-join EXISTS patterns into simpler predicates.
		 *
		 * @param AstInterface $node Root of the subtree to process
		 * @param bool $includeNulls If true, replace simplified EXISTS with TRUE predicate;
		 *                           if false, replace with more restrictive predicate that excludes nulls
		 * @return AstInterface Possibly rewritten node (new instance if changes made, original if unchanged)
		 */
		private function rewriteExistsNodesWithinAndTree(AstInterface $node, bool $includeNulls): AstInterface {
			// Handle AND nodes: recursively process both sides of the binary operator
			if ($node instanceof AstBinaryOperator && $node->getOperator() === 'AND') {
				// Recursively rewrite left and right subtrees
				$l = $this->rewriteExistsNodesWithinAndTree($node->getLeft(), $includeNulls);
				$r = $this->rewriteExistsNodesWithinAndTree($node->getRight(), $includeNulls);
				
				// Optimization: only create new node if children actually changed
				// This preserves object identity when no rewriting occurred
				if ($l === $node->getLeft() && $r === $node->getRight()) {
					return $node;
				}
				
				// Create new AND node with potentially rewritten children
				return new AstBinaryOperator($l, $r, 'AND');
			}
			
			// Handle EXISTS subqueries: attempt to simplify self-join patterns
			if ($node instanceof AstSubquery && $node->getType() === AstSubquery::TYPE_EXISTS) {
				// Delegate to specialized method that analyzes the EXISTS subquery structure
				// Returns null if no simplification is possible, otherwise returns replacement predicate
				$replacement = $this->simplifySingleExistsNodeIfSelfJoin($node, $includeNulls);
				
				// Successfully simplified: return the replacement predicate
				if ($replacement !== null) {
					return $replacement;
				}
				
				// No simplification possible: fall through to return original node
			}
			
			// Base case: node is not an AND operator or EXISTS subquery, or couldn't be simplified
			// Return unchanged (this includes other operators like OR, comparison operators, literals, etc.)
			return $node;
		}
		
		/**
		 * Simplifies EXISTS(subquery) to NOT NULL checks when it represents a trivial self-join.
		 *
		 * A self-join is considered trivial when:
		 * - The subquery joins the same entity on identical columns
		 * - The join condition only compares outer.col = inner.col patterns
		 * - No additional WHERE conditions exist beyond the join predicates
		 *
		 * Example transformation:
		 * EXISTS(SELECT 1 FROM users u2 WHERE u1.id = u2.id AND u1.name = u2.name)
		 * becomes: u1.id IS NOT NULL AND u1.name IS NOT NULL
		 *
		 * @param AstSubquery $existsNode The EXISTS subquery to analyze
		 * @param bool $includeNulls When true, returns TRUE predicate (includes NULL values)
		 *                           When false, generates NOT NULL checks for join columns
		 * @return AstInterface|null Simplified predicate, or null if optimization not applicable
		 * @throws \InvalidArgumentException If subquery structure is malformed
		 */
		private function simplifySingleExistsNodeIfSelfJoin(AstSubquery $existsNode, bool $includeNulls): ?AstInterface {
			// Early exit: self-join optimization requires correlated ranges
			// Non-correlated subqueries cannot be simplified to column checks
			$correlatedRanges = $existsNode->getCorrelatedRanges();
			if (empty($correlatedRanges)) {
				return null;
			}
			
			// Build hash-based lookup for O(1) correlated range identification
			// Used later to distinguish outer vs inner table references in join conditions
			$correlatedRangeSet = [];
			foreach ($correlatedRanges as $range) {
				$correlatedRangeSet[spl_object_hash($range)] = true;
			}
			
			// Extract WHERE clause conditions from the subquery
			// These must contain only equality joins for the optimization to apply
			$conditions = $existsNode->getConditions();
			if ($conditions === null) {
				return null;
			}
			
			// Parse join conditions into outer/inner column pairs
			// Only simple equality conditions (outer.col = inner.col) are supported
			$joinPairs = [];
			if (!$this->collectOuterInnerIdPairs($conditions, $correlatedRangeSet, $joinPairs) || empty($joinPairs)) {
				return null;
			}
			
			// Verify structural requirements for self-join optimization:
			// - Same underlying table/entity for outer and inner references
			// - Identical column sets being compared
			// - No complex expressions or transformations in join predicates
			if (!$this->isValidSelfJoin($joinPairs)) {
				return null;
			}
			
			// Special optimization case: when includeNulls=true, the EXISTS becomes trivial
			// Since we're checking if a row exists in the same table with same values,
			// and NULLs are included, this is always true (assuming the outer row exists)
			if ($includeNulls) {
				// Return literal TRUE condition (1=1)
				return new AstExpression(new AstNumber(1), new AstNumber(1), '=');
			}
			
			// Standard case: transform EXISTS to conjunction of NOT NULL checks
			// Logic: EXISTS(SELECT ... WHERE outer.a=inner.a AND outer.b=inner.b)
			// is equivalent to (outer.a IS NOT NULL AND outer.b IS NOT NULL)
			// because if outer columns are non-null, the self-join will always find the same row
			return $this->buildNotNullChain($joinPairs);
		}
		
		/**
		 * Validates that all join pairs represent a self-join on the same entity and properties.
		 * @param array $joinPairs
		 * @return bool
		 */
		private function isValidSelfJoin(array $joinPairs): bool {
			foreach ($joinPairs as [$outerIdentifier, $innerIdentifier]) {
				if (!$this->isSameEntityAndProperty($outerIdentifier, $innerIdentifier)) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Checks if two identifiers reference the same entity and property.
		 * @param AstIdentifier $outer
		 * @param AstIdentifier $inner
		 * @return bool
		 */
		private function isSameEntityAndProperty(AstIdentifier $outer, AstIdentifier $inner): bool {
			// Verify both identifiers have valid ranges
			$outerRange = $outer->getBaseIdentifier()->getRange();
			$innerRange = $inner->getBaseIdentifier()->getRange();
			
			if ($outerRange === null || $innerRange === null) {
				return false;
			}
			
			// Must be the same entity
			if ($outerRange->getEntityName() !== $innerRange->getEntityName()) {
				return false;
			}
			
			// Must reference the same property
			$outerProperty = $outer->getPropertyName();
			$innerProperty = $inner->getPropertyName();
			
			return
				$outerProperty !== '' &&
				$innerProperty !== '' &&
				$outerProperty === $innerProperty;
		}
		
		/**
		 * Builds a chain of IS NOT NULL conditions connected by AND operators.
		 */
		private function buildNotNullChain(array $joinPairs): ?AstInterface {
			$notNullChain = null;
			
			foreach ($joinPairs as [$outerIdentifier, $_innerIdentifier]) {
				$notNullCheck = new AstCheckNotNull($outerIdentifier->deepClone());
				
				$notNullChain = $notNullChain === null
					? $notNullCheck
					: new AstBinaryOperator($notNullChain, $notNullCheck, 'AND');
			}
			
			return $notNullChain;
		}
		
		/**
		 * Scans a predicate tree for simple equality comparisons (id = id) where
		 * exactly one identifier belongs to an inner range and one to an outer range.
		 * Only processes AND operations and equality expressions.
		 * @param AstInterface $expr The predicate subtree to analyze
		 * @param array<string,bool> $innerSet Hash map of inner ranges (spl_object_hash => true)
		 * @param array<int,array{0:AstIdentifier,1:AstIdentifier}> &$pairs Output array of [outer, inner] pairs
		 * @return bool True if all leaves are eligible equality comparisons, false otherwise
		 */
		private function collectOuterInnerIdPairs(AstInterface $expr, array $innerSet, array &$pairs): bool {
			// Recursively process AND operations
			if ($expr instanceof AstBinaryOperator && $expr->getOperator() === 'AND') {
				return
					$this->collectOuterInnerIdPairs($expr->getLeft(), $innerSet, $pairs) &&
					$this->collectOuterInnerIdPairs($expr->getRight(), $innerSet, $pairs);
			}
			
			// Process equality expressions
			if ($expr instanceof AstExpression && $expr->getOperator() === '=') {
				$leftOperand = $expr->getLeft();
				$rightOperand = $expr->getRight();
				
				// Both operands must be identifiers
				if (!($leftOperand instanceof AstIdentifier) || !($rightOperand instanceof AstIdentifier)) {
					return false;
				}
				
				// Get base identifiers and their ranges
				$leftBase = $leftOperand->getBaseIdentifier();
				$rightBase = $rightOperand->getBaseIdentifier();
				$leftRange = $leftBase->getRange();
				$rightRange = $rightBase->getRange();
				
				// Both identifiers must have valid ranges
				if ($leftRange === null || $rightRange === null) {
					return false;
				}
				
				// Check which identifiers belong to inner ranges
				$leftIsInner = isset($innerSet[spl_object_hash($leftRange)]);
				$rightIsInner = isset($innerSet[spl_object_hash($rightRange)]);
				
				// Exactly one identifier must be inner, one outer
				if ($leftIsInner === $rightIsInner) {
					return false; // Both inner or both outer - not eligible
				}
				
				// Store in canonical order: [outer identifier, inner identifier]
				if ($leftIsInner) {
					$pairs[] = [$rightOperand, $leftOperand]; // right=outer, left=inner
				} else {
					$pairs[] = [$leftOperand, $rightOperand]; // left=outer, right=inner
				}
				
				return true;
			}
			
			// All other expression types are ineligible
			return false;
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
		 *
		 * Window functions operate over a set of rows related to the current row, making them
		 * suitable replacements for aggregates in certain scenarios. However, this transformation
		 * is only valid under specific conditions to maintain query correctness.
		 *
		 * Rewriting requirements:
		 * 1. Basic compatibility - The aggregate function itself must support window syntax
		 * 2. Single table query - Window functions work on row sets from a single source
		 * 3. Consistent references - The aggregate must reference the same table as the query
		 * 4. Uniform select items - All selected expressions must reference the same table
		 *
		 * Why these restrictions matter:
		 * - Multi-table queries would require complex partitioning logic
		 * - Cross-table references in aggregates would break window semantics
		 * - Mixed table references in select items would produce inconsistent row counts
		 *
		 * @param AstRetrieve $root Query root containing the complete SELECT statement
		 * @param AstInterface $aggregate Specific aggregate function being analyzed for rewriting
		 * @return bool True if the aggregate can be safely rewritten as a window function
		 */
		private function canRewriteAsWindowFunction(AstRetrieve $root, AstInterface $aggregate): bool {
			// VALIDATION 1: Basic Window Function Compatibility
			// ================================================
			// Check if this aggregate function type (SUM, COUNT, etc.) can be expressed
			// as a window function. Some aggregates have no window equivalent or require
			// special handling that makes them unsuitable for automatic rewriting.
			if (!$this->passesWindowFunctionBasics($aggregate)) {
				return false;
			}
			
			// Extract all table/view references from the main query
			$queryRanges = $root->getRanges();
			
			// VALIDATION 2: Single Table Requirement
			// ======================================
			// Window functions work best with single-table queries. Multi-table queries
			// introduce complexity around partitioning, ordering, and result set boundaries
			// that can lead to incorrect results after rewriting.
			//
			// Example of why this matters:
			//   SELECT a.id, SUM(b.amount) FROM users a JOIN orders b ON a.id = b.user_id
			//
			// Rewriting SUM(b.amount) as a window function would change the semantics
			// because window functions don't respect JOIN boundaries the same way.
			if (count($queryRanges) !== 1) {
				return false;
			}
			
			// Store the single table reference for consistency checking
			$singleRange = $queryRanges[0];
			
			// VALIDATION 3: Aggregate Table Reference Consistency
			// ===================================================
			// The aggregate function must reference exactly the same table as the main query.
			// This prevents scenarios where the aggregate operates on a different data source
			// than the rest of the query, which would break window function semantics.
			//
			// Example of invalid case:
			//   SELECT users.name, (SELECT COUNT(*) FROM orders) FROM users
			//
			// The COUNT(*) references 'orders' while the main query uses 'users'.
			// This can't be rewritten as a window function because window functions
			// operate on the same row set as their containing query.
			$aggregateRanges = $this->collectRangesFromNode($aggregate);
			
			if (count($aggregateRanges) !== 1 || $aggregateRanges[0] !== $singleRange) {
				return false;
			}
			
			// VALIDATION 4: Uniform Select Item References
			// ============================================
			// All expressions in the SELECT clause must reference the same single table.
			// Mixed references would create inconsistent row counts after window function
			// rewriting, since window functions operate row-by-row on a consistent dataset.
			//
			// Example of invalid case:
			//   SELECT users.name, departments.budget, SUM(users.salary)
			//   FROM users, departments
			//
			// After rewriting SUM(users.salary) to a window function, each row would
			// show the total salary, but departments.budget would create a Cartesian
			// product that doesn't match the intended aggregation scope.
			foreach ($root->getValues() as $selectItem) {
				// Skip the aggregate we're analyzing - we already validated it above
				if ($selectItem->getExpression() === $aggregate) {
					continue; // This is our target aggregate, already checked
				}
				
				// Check what tables/views this select item references
				$itemRanges = $this->collectRangesFromNode($selectItem->getExpression());
				
				// Each select item must reference exactly the same single table
				if (count($itemRanges) !== 1 || $itemRanges[0] !== $singleRange) {
					return false; // Select item references different/multiple tables
				}
			}
			
			// All validations passed - the aggregate can be safely rewritten as a window function
			// The rewrite will preserve query semantics while potentially improving performance
			// by eliminating grouping operations in favor of analytical window processing.
			return true;
		}
		
		/**
		 * Basic window support checks: no conditions, not DISTINCT variant, DB supports,
		 * and the aggregate type is allowed.
		 * @param AstInterface $aggregate Aggregate to test
		 * @return bool True if basic constraints are satisfied
		 */
		private function passesWindowFunctionBasics(AstInterface $aggregate): bool {
			// Window functions can't have their own filters
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// DISTINCT variants commonly unsupported for window context
			if (in_array(get_class($aggregate), $this->distinctAggregateTypes, true)) {
				return false;
			}
			
			// Database capability
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false;
			}
			
			// ??
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