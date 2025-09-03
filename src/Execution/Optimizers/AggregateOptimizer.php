<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	
	/**
	 * Query Optimizer for Aggregate Function Performance
	 *
	 * Transforms aggregate functions (SUM, COUNT, AVG, MIN, MAX) into optimized scalar
	 * subqueries to improve query performance. This optimization isolates aggregates with
	 * their minimal required data scope, reducing unnecessary JOINs and table scans.
	 *
	 * TRANSFORMATION EXAMPLE:
	 * Before: SELECT o.id, SUM(oi.price) FROM orders o JOIN order_items oi ON o.id = oi.order_id
	 * After:  SELECT o.id, (SELECT SUM(oi.price) FROM order_items oi WHERE oi.order_id = o.id)
	 *
	 * KEY FEATURES:
	 * - Minimizes JOIN complexity by isolating aggregate calculations
	 * - Preserves semantic correctness through correlation analysis
	 * - Handles both nullable (SUM, COUNT, AVG) and non-nullable (SUMU, COUNTU, AVGU) variants
	 * - Maintains original WHERE conditions within subquery scope
	 *
	 * LIMITATIONS:
	 * - COUNT(*) syntax not supported - use COUNT(expression) instead
	 * - Requires compatible RangeUsageAnalyzer for optimal performance
	 *
	 */
	class AggregateOptimizer {
		private EntityManager $entityManager;
		private AstNodeReplacer $nodeReplacer;
		private AstUtilities $astUtilities;
		private RangeUsageAnalyzer $analyzer;
		private RangePartitioner $rangePartitioner;
		private AnchorManager $anchorManager;
		private RangeOptimizer $rangeOptimizer;
		
		/**
		 * Registry of all supported aggregate function AST node types
		 *
		 * Includes both nullable and non-nullable variants:
		 * - SUM/SUMU: Arithmetic summation with/without null handling
		 * - COUNT/COUNTU: Row counting with/without null handling
		 * - AVG/AVGU: Arithmetic mean with/without null handling
		 * - MIN/MAX: Extrema functions (inherently null-safe)
		 * @var array<class-string>
		 */
		private array $aggregateTypes = [
			AstSum::class,
			AstSumU::class,
			AstCount::class,
			AstCountU::class,
			AstAvg::class,
			AstAvgU::class,
			AstMin::class,
			AstMax::class,
		];
		
		/**
		 * Registry of all distinct aggregate functions
		 * @var array|string[]
		 */
		private array $distinctClasses = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class
		];
		
		/**
		 * Initialize optimizer with required dependencies
		 * @param EntityManager $entityManager Provides access to entity metadata and storage layer
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->astUtilities = new AstUtilities();
			$this->nodeReplacer = new AstNodeReplacer();
			$this->analyzer = new RangeUsageAnalyzer($entityManager->getEntityStore());
			$this->rangePartitioner = new RangePartitioner($this->astUtilities);
			$this->anchorManager = new AnchorManager($this->astUtilities);
			$this->rangeOptimizer = new RangeOptimizer($entityManager);
		}
		
		/**
		 * Main optimization entry point - processes entire AST for aggregate functions
		 *
		 * Scans the complete AST tree to locate all aggregate nodes and converts each
		 * into an optimized scalar subquery. The process preserves query semantics while
		 * potentially improving execution performance through reduced JOIN complexity.
		 *
		 * OPTIMIZATION STRATEGY:
		 * 1. Identify all aggregate function nodes in the AST
		 * 2. Analyze data dependencies for each aggregate
		 * 3. Create minimal correlated subqueries with only required ranges
		 * 4. Replace original aggregate nodes with subquery equivalents
		 *
		 * @param AstRetrieve $ast Root query AST node to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Locate all aggregate functions throughout the entire query tree
			foreach ($this->findAllAggregateNodes($ast) as $agg) {
				// Transform each aggregate into an optimized scalar subquery
				// Note: We use TYPE_SCALAR regardless of context (SELECT vs WHERE)
				// as the optimization strategy remains consistent
				$this->optimizeAggregateNode($ast, $agg, AstSubquery::TYPE_SCALAR);
			}
		}
		
		/**
		 * Core optimization logic - transforms single aggregate into correlated subquery
		 *
		 * This method implements the heart of the optimization by:
		 * 1. Analyzing which table ranges (FROM/JOIN sources) are actually needed
		 * 2. Partitioning ranges into "live" (data-contributing) vs "correlation-only"
		 * 3. Creating a minimal subquery with only essential ranges
		 * 4. Ensuring proper correlation back to the outer query
		 *
		 * DEPENDENCY ANALYSIS:
		 * - Expression dependencies: Ranges referenced in the aggregate expression itself
		 * - Condition dependencies: Ranges referenced in the aggregate's WHERE conditions
		 * - Join dependencies: Ranges required to maintain referential integrity
		 *
		 * @param AstRetrieve $ast Root query containing the aggregate
		 * @param AstInterface $agg Specific aggregate node to optimize
		 * @param string $subQueryType Subquery type constant (always TYPE_SCALAR for aggregates)
		 */
		private function optimizeAggregateNode(AstRetrieve $ast, AstInterface $agg, string $subQueryType): void {
			// STEP 1: Create isolated working copy of table ranges to avoid mutation
			// Deep cloning ensures the outer query structure remains untouched
			$ranges = array_map(static fn(AstRange $r) => $r->deepClone(), $ast->getRanges());
			
			// STEP 2: Comprehensive dependency analysis for this specific aggregate
			// Determines which table ranges are referenced in expressions vs conditions,
			// and identifies null-safety requirements for proper optimization
			$usage = $this->analyzeUsageForAggregate($agg, $ranges);
			$usedInExpr = $usage['usedInExpr'];     // Ranges referenced in aggregate expression
			$usedInCond = $usage['usedInCond'];     // Ranges referenced in aggregate conditions
			$hasIsNullInCond = $usage['hasIsNullInCond']; // Null-check predicates affecting ranges
			$nonNullableUse = $usage['nonNullableUse'];   // Ranges that must produce non-null results
			
			// STEP 3: Build cross-reference map for JOIN relationship analysis
			// Essential for maintaining referential integrity when pruning ranges
			$joinReferences = $this->rangePartitioner->buildJoinReferenceMap($ranges);
			
			// STEP 4: Partition ranges into essential vs correlation-only categories
			// Live ranges actively contribute data; correlation ranges only provide linking
			[$liveRanges, $correlationOnlyRanges] = $this->rangePartitioner->separateLiveAndCorrelationRanges(
				$ranges,
				$usedInExpr,
				$usedInCond,
				$joinReferences
			);
			
			// STEP 5: Safety fallback for edge cases where no live ranges detected
			// Ensures the subquery has at least one meaningful data source
			if (empty($liveRanges)) {
				$liveRanges = $this->fallbackLiveRangesForOwner($ranges, $agg);
			}
			
			// STEP 6: Extract and prepare WHERE conditions for the subquery
			// Only the aggregate's own conditions are included; outer query predicates
			// remain in the outer scope to preserve correlation semantics
			$finalWhere = $this->astUtilities->combinePredicatesWithAnd([$this->getConditionsIfAny($agg)]);
			
			// STEP 7: Filter range list to include only live (data-contributing) ranges
			// Maintains original ordering to preserve JOIN sequence semantics
			$keptRanges = $this->rangePartitioner->filterToLiveRangesOnly($ranges, $liveRanges);
			
			// STEP 8: Ensure proper anchor range for correlation
			// An anchor range provides the correlation link back to the outer query.
			// This step may reorder or modify ranges to establish clear correlation paths.
			$keptRanges = $this->anchorManager->ensureSingleAnchorRangeForOwner(
				$keptRanges,
				$agg,
				$finalWhere,
				$usedInExpr,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
			
			if ($this->canFlattenToWindow($ast, $agg)) {
				$aggregationNode = $this->deepCloneAggregateWithoutConditions($agg);
				
				$window = new AstSubquery(
					AstSubquery::TYPE_WINDOW,
					$aggregationNode,
					[],                  // no correlated ranges needed for OVER ()
					null        // no WHERE
				);
				
				$this->nodeReplacer->replaceChild($agg->getParent(), $agg, $window);
				return; // done
			}
			
			// STEP 9: Construct the optimized subquery and perform AST replacement
			// Create clean aggregate node without embedded conditions (moved to subquery WHERE)
			$aggregationNode = $this->deepCloneAggregateWithoutConditions($agg);
			$subQuery = new AstSubquery($subQueryType, $aggregationNode, $keptRanges, $finalWhere);
			
			// Replace the original aggregate node with the new subquery in the AST
			$this->nodeReplacer->replaceChild($agg->getParent(), $agg, $subQuery);
		}
		
		/**
		 * Discover all aggregate function nodes within the query AST
		 *
		 * Uses the visitor pattern to traverse the entire AST tree and collect
		 * all nodes matching registered aggregate types. This ensures no aggregate
		 * functions are missed regardless of their location in the query structure
		 * (SELECT list, WHERE clauses, HAVING clauses, ORDER BY, etc.).
		 *
		 * @param AstRetrieve $ast Root query node to scan
		 * @return AstInterface[] Array of discovered aggregate nodes
		 */
		private function findAllAggregateNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes($this->aggregateTypes);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Analyze table range usage patterns for a specific aggregate function
		 *
		 * Determines how each table range (FROM/JOIN source) is utilized by the aggregate:
		 * - Expression usage: Range provides data for the aggregate calculation
		 * - Condition usage: Range is referenced in aggregate-specific WHERE conditions
		 * - Null handling: Whether the range participates in null-check predicates
		 * - Non-nullable requirements: Whether the range must produce non-null results
		 *
		 * This analysis is critical for determining which ranges can be safely excluded
		 * from the optimized subquery without affecting result correctness.
		 *
		 * FALLBACK BEHAVIOR:
		 * If the enhanced analyzer is unavailable, uses conservative heuristics based
		 * on identifier collection to ensure safe operation across different codebase versions.
		 *
		 * @param AstInterface $owner The aggregate node being analyzed
		 * @param array $ranges Available table ranges from the query
		 * @return array{usedInExpr: array<string,bool>, usedInCond: array<string,bool>, hasIsNullInCond: array<string,bool>, nonNullableUse: array<string,bool>}
		 */
		private function analyzeUsageForAggregate(AstInterface $owner, array $ranges): array {
			// Prefer enhanced analyzer method if available in current codebase version
			if (method_exists($this->analyzer, 'analyzeAggregate')) {
				/** @var array $res */
				$res = $this->analyzer->analyzeAggregate($owner, $ranges);
				return $res;
			}
			
			// FALLBACK: Conservative analysis using basic identifier collection
			// This ensures compatibility with older codebase versions while providing
			// reasonable optimization results through simplified usage detection
			
			// Initialize all ranges as unused across all categories
			$names = array_map(static fn(AstRange $r) => $r->getName(), $ranges);
			$usedInExpr = array_fill_keys($names, false);
			$usedInCond = array_fill_keys($names, false);
			$hasIsNullInCond = array_fill_keys($names, false);   // Conservative: assume no IS NULL predicates
			$nonNullableUse = array_fill_keys($names, false);   // Conservative: assume all ranges nullable
			
			// Mark ranges referenced in the aggregate's main expression (e.g., SUM(table.column))
			foreach ($this->astUtilities->collectIdentifiersFromAst($this->getIdentifierIfAny($owner)) as $id) {
				$usedInExpr[$id->getRange()->getName()] = true;
			}
			
			// Mark ranges referenced in the aggregate's WHERE conditions
			$cond = $this->getConditionsIfAny($owner);
			if ($cond !== null) {
				$condIds = $this->astUtilities->collectIdentifiersFromAst($cond);
				foreach ($condIds as $id) {
					$usedInCond[$id->getRange()->getName()] = true;
				}
			}
			
			return [
				'usedInExpr'      => $usedInExpr,
				'usedInCond'      => $usedInCond,
				'hasIsNullInCond' => $hasIsNullInCond,
				'nonNullableUse'  => $nonNullableUse,
			];
		}
		
		/**
		 * Determine minimal viable table ranges when automatic detection fails
		 *
		 * This safety mechanism ensures that even when the sophisticated range analysis
		 * fails to identify live ranges, the optimizer can still produce a functional
		 * subquery by falling back to basic heuristics.
		 *
		 * SELECTION STRATEGY:
		 * 1. Prefer ranges directly referenced in the aggregate's expression
		 * 2. Fall back to the first available range if no direct references found
		 * 3. Return empty set only if no ranges available at all
		 *
		 * This conservative approach prioritizes correctness over optimization when
		 * sophisticated analysis is unavailable or fails.
		 *
		 * @param AstRange[] $ranges All available table ranges
		 * @param AstInterface $owner The aggregate node requiring live ranges
		 * @return array<string,AstRange> Selected live ranges indexed by name
		 */
		private function fallbackLiveRangesForOwner(array $ranges, AstInterface $owner): array {
			// Use enhanced method if available for more sophisticated fallback logic
			if (method_exists($this->rangePartitioner, 'selectFallbackLiveRangesForOwner')) {
				/** @var array<string,AstRange> $res */
				$res = $this->rangePartitioner->selectFallbackLiveRangesForOwner($ranges, $owner);
				return $res;
			}
			
			// BASIC FALLBACK: Select ranges based on identifier analysis
			
			// Create lookup map for efficient range resolution
			$rangesByName = [];
			foreach ($ranges as $r) {
				$rangesByName[$r->getName()] = $r;
			}
			
			$live = [];
			
			// First preference: ranges referenced in the aggregate expression
			foreach ($this->astUtilities->collectIdentifiersFromAst($this->getIdentifierIfAny($owner)) as $id) {
				$name = $id->getRange()->getName();
				
				if (isset($rangesByName[$name])) {
					$live[$name] = $rangesByName[$name];
				}
			}
			
			// If expression-based selection succeeded, use it
			if (!empty($live)) {
				return $live;
			}
			
			// Ultimate fallback: use first available range
			// Better to have a potentially suboptimal but functional subquery
			// than to fail the optimization entirely
			$first = reset($ranges);
			return $first ? [$first->getName() => $first] : [];
		}
		
		/**
		 * Create clean aggregate node copy without embedded WHERE conditions
		 *
		 * The optimization strategy moves aggregate-specific WHERE conditions into
		 * the subquery's WHERE clause rather than keeping them embedded within
		 * the aggregate node itself. This separation provides cleaner subquery
		 * structure and more predictable SQL generation.
		 *
		 * @param AstInterface $agg Original aggregate node to clone
		 * @return AstInterface Clean aggregate clone with conditions removed
		 */
		private function deepCloneAggregateWithoutConditions(AstInterface $agg): AstInterface {
			$clone = $agg->deepClone();
			
			// Remove embedded conditions if the aggregate node supports them
			if (method_exists($clone, 'setConditions')) {
				$clone->setConditions(null);
			}
			
			return $clone;
		}
		
		/**
		 * Safe accessor for aggregate node's main expression/identifier
		 *
		 * Different aggregate types may store their primary expression in different
		 * ways. This method provides a unified interface while handling cases where
		 * the node doesn't support identifier access.
		 *
		 * @param AstInterface $node Aggregate node to examine
		 * @return AstInterface|null The node's main expression, or null if not accessible
		 */
		private function getIdentifierIfAny(AstInterface $node): ?AstInterface {
			return method_exists($node, 'getIdentifier') ? $node->getIdentifier() : null;
		}
		
		/**
		 * Safe accessor for aggregate node's embedded WHERE conditions
		 *
		 * Some aggregate nodes support embedded WHERE conditions (e.g., conditional
		 * aggregation). This method provides safe access while handling nodes that
		 * don't support this functionality.
		 *
		 * @param AstInterface $node Aggregate node to examine
		 * @return AstInterface|null The node's conditions, or null if not accessible
		 */
		private function getConditionsIfAny(AstInterface $node): ?AstInterface {
			return method_exists($node, 'getConditions') ? $node->getConditions() : null;
		}
		
		/**
		 * Decide if an aggregate can be emitted as a window function AGG(...) OVER ().
		 *
		 * Rules:
		 *  - No aggregate-level conditions (we don't synthesize FILTER/CASE here)
		 *  - No DISTINCT-like variants (SUMU/AVGU/COUNTU)
		 *  - Engine supports window functions
		 *  - Aggregate references exactly one alias
		 *  - Considering only *active* outer ranges (main + joined ranges with include flag,
		 *    minus inert LEFT JOINs that are referenced only in their own ON), there must be
		 *    exactly one active range, and it must be the same alias the aggregate references.
		 *
		 * Side effect:
		 *  - Marks trivially-unused LEFT JOIN ranges as includeAsJoin=false so later phases
		 *    don't emit them.
		 */
		private function canFlattenToWindow(AstRetrieve $root, AstInterface $agg): bool {
			// Guard 1: no aggregate-level WHERE/conditions
			if ($this->getConditionsIfAny($agg) !== null) {
				return false;
			}
			
			// Guard 2: distinct-like variants excluded
			if (in_array(get_class($agg), $this->distinctClasses, true)) {
				return false;
			}
			
			// Guard 3: window support required
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false;
			}
			
			// Aggregate must reference exactly one alias
			$ids = $this->astUtilities->collectIdentifiersFromAst($this->getIdentifierIfAny($agg));
			$names = array_values(array_unique(array_map(static fn($id) => $id->getRange()->getName(), $ids)));
			if (count($names) !== 1) {
				return false;
			}
			$aggAlias = $names[0];
			
			// Helper: count alias occurrences in OUTER scope, excluding subqueries and
			// excluding a specific join predicate subtree (if provided).
			$countAliasOutsideOwnJoin = function (AstRetrieve $tree, string $alias, $ownJoinProp = null): int {
				$count = 0;
				$stack = [$tree];
				while ($stack) {
					/** @var mixed $node */
					$node = array_pop($stack);
					
					if ($ownJoinProp !== null && $node === $ownJoinProp) {
						continue; // skip this range's ON subtree
					}
					if ($node instanceof AstSubquery) {
						continue; // do not descend into subqueries
					}
					
					if ($node instanceof \Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier) {
						if ($node->getRange()->getName() === $alias) {
							$count++;
						}
					}
					
					if ($node instanceof AstInterface && method_exists($node, 'getChildren')) {
						foreach ($node->getChildren() as $child) {
							if ($child instanceof AstInterface) {
								$stack[] = $child;
							}
						}
					}
				}
				return $count;
			};
			
			// Consider only *active* ranges:
			//  - main range is always active
			//  - joined range is active if includeAsJoin==true (or missing flag defaults true)
			//  - HOWEVER, if a joined range is a trivially-unused LEFT JOIN (only appears in its own ON),
			//    we mark it includeAsJoin=false here and treat it as inactive.
			$activeAliases = [];
			
			foreach ($root->getRanges() as $r) {
				$isMain = !method_exists($r, 'getJoinProperty') || $r->getJoinProperty() === null;
				
				if ($isMain) {
					$mainAlias = $r->getName();
					$activeAliases[$mainAlias] = true;
					continue;
				}
				
				// Respect "required" joins
				if (method_exists($r, 'isRequired') && $r->isRequired()) {
					$activeAliases[$r->getName()] = true;
					continue;
				}
				
				// Current include flag (default true)
				$include = true;
				
				if (method_exists($r, 'shouldIncludeAsJoin')) {
					$include = (bool)$r->shouldIncludeAsJoin();
				} elseif (method_exists($r, 'getIncludeAsJoin')) {
					$include = (bool)$r->getIncludeAsJoin();
				}
				
				// If currently included, check if it's inert; if inert, flip it off.
				if ($include) {
					$joinProp = method_exists($r, 'getJoinProperty') ? $r->getJoinProperty() : null;
					$alias = $r->getName();
					$outerUse = $countAliasOutsideOwnJoin($root, $alias, $joinProp);
					
					if ($outerUse === 0 && method_exists($r, 'setIncludeAsJoin')) {
						// Inert LEFT JOIN (only used in its own ON): exclude it
						$r->setIncludeAsJoin(false);
						$include = false;
					}
				}
				
				if ($include) {
					$activeAliases[$r->getName()] = true;
				}
			}
			
			// There must be exactly one active alias
			if (count($activeAliases) !== 1) {
				return false;
			}
			
			// And it must be the one the aggregate references
			$onlyActive = array_key_first($activeAliases);
			if ($onlyActive !== $aggAlias) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Return the number of "active" outer ranges (i.e., ones that will actually be emitted in SQL).
		 * We ignore ranges whose JOIN has been marked as excluded.
		 */
		private function countActiveOuterRanges(AstRetrieve $root): int {
			$count = 0;
			foreach ($root->getRanges() as $r) {
				// Main range has no join property → always active
				$isMain = method_exists($r, 'getJoinProperty') ? $r->getJoinProperty() === null : true;
				
				// Respect includeAsJoin=false for joined ranges
				$include = true;
				if (!$isMain) {
					if (method_exists($r, 'shouldIncludeAsJoin')) {
						$include = $r->shouldIncludeAsJoin();
					} elseif (method_exists($r, 'getIncludeAsJoin')) {
						$include = (bool)$r->getIncludeAsJoin();
					}
				}
				
				if ($isMain || $include) {
					$count++;
				}
			}
			return $count;
		}
		
		/**
		 * For this aggregate, mark any LEFT JOIN ranges that are unused in the OUTER scope
		 * (i.e., referenced nowhere except their own ON predicate) as not included.
		 * This does NOT descend into subqueries, so inner aliases won't block exclusion.
		 */
		private function excludeUnusedLeftJoinsForThisAggregate(AstRetrieve $root): void {
			// Find main range
			$main = null;
			foreach ($root->getRanges() as $r) {
				if (method_exists($r, 'getJoinProperty') && $r->getJoinProperty() === null) {
					$main = $r;
					break;
				}
			}
			
			foreach ($root->getRanges() as $r) {
				// Skip main range and any range already required
				if ($r === $main || (method_exists($r, 'isRequired') && $r->isRequired())) {
					continue;
				}
				
				// Only consider joined ranges
				if (!method_exists($r, 'getJoinProperty') || $r->getJoinProperty() === null) {
					continue;
				}
				
				// If the alias does not appear anywhere in OUTER scope except its own ON, exclude it
				if ($this->countAliasOutsideOwnJoinShallow($root, $r) === 0) {
					if (method_exists($r, 'setIncludeAsJoin')) {
						$r->setIncludeAsJoin(false);
					}
				}
			}
		}
		
		/**
		 * Count occurrences of a range alias in OUTER scope, excluding subqueries and
		 * excluding the range's own join predicate subtree.
		 */
		private function countAliasOutsideOwnJoinShallow(AstRetrieve $root, AstRange $range): int {
			$alias = $range->getName();
			$joinProp = method_exists($range, 'getJoinProperty') ? $range->getJoinProperty() : null;
			
			$count = 0;
			$stack = [$root];
			
			while ($stack) {
				/** @var mixed $node */
				$node = array_pop($stack);
				
				if ($node === $joinProp) {
					// ignore the ON subtree for that range
					continue;
				}
				if ($node instanceof \Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery) {
					// do not descend into subqueries
					continue;
				}
				
				if ($node instanceof \Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier) {
					if ($node->getRange()->getName() === $alias) {
						$count++;
					}
				}
				
				if ($node instanceof \Quellabs\ObjectQuel\ObjectQuel\AstInterface && method_exists($node, 'getChildren')) {
					foreach ($node->getChildren() as $child) {
						if ($child instanceof \Quellabs\ObjectQuel\ObjectQuel\AstInterface) {
							$stack[] = $child;
						}
					}
				}
			}
			
			return $count;
		}
	}