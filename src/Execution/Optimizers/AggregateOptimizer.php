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
			$usage = $this->analyzer->analyzeAggregate($agg, $ranges);
			
			$usedInExpr = $usage['usedInExpr'];            // Ranges referenced in aggregate expression
			$usedInCond = $usage['usedInCond'];            // Ranges referenced in aggregate conditions
			$hasIsNullInCond = $usage['hasIsNullInCond'];  // Null-check predicates affecting ranges
			$nonNullableUse = $usage['nonNullableUse'];    // Ranges that must produce non-null results
			
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
				$liveRanges = $this->rangePartitioner->selectFallbackLiveRangesForOwner($ranges, $agg);
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
		 * @param AstRetrieve $ast Root query node to scan
		 * @return AstInterface[] Array of discovered aggregate nodes
		 */
		private function findAllAggregateNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes($this->aggregateTypes);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}

		/**
		 * This optimization strategy moves aggregate-specific WHERE conditions into
		 * the subquery's WHERE clause rather than keeping them embedded within
		 * the aggregate node itself. This separation provides cleaner subquery
		 * structure and more predictable SQL generation.
		 * @param AstInterface $agg Original aggregate node to clone
		 * @return AstInterface Clean aggregate clone with conditions removed
		 */
		private function deepCloneAggregateWithoutConditions(AstInterface $agg): AstInterface {
			$clone = $agg->deepClone();
			$clone->setConditions(null);
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
		 * This method orchestrates all the checks required to determine if an aggregate
		 * can be safely converted to a window function. It validates basic prerequisites,
		 * analyzes alias usage, manages join states, and ensures exactly one active range.
		 *
		 * @param AstRetrieve $root The root AST retrieve node containing ranges and conditions
		 * @param AstInterface $agg The aggregate node to evaluate for window function conversion
		 * @return bool True if the aggregate can be flattened to a window function, false otherwise
		 * @see passesBasicWindowChecks() For basic validation rules
		 * @see getAggregateAlias() For alias extraction logic
		 * @see markInertLeftJoinsAsExcluded() For side effects on join ranges
		 */
		private function canFlattenToWindow(AstRetrieve $root, AstInterface $agg): bool {
			// Check basic window function compatibility (e.g., supported aggregate types,
			// no DISTINCT modifiers, proper window frame requirements)
			if (!$this->passesBasicWindowChecks($agg)) {
				return false;
			}
			
			// Extract the table alias that this aggregate function operates on
			// Returns null if aggregate spans multiple tables or has no clear alias
			$aggAlias = $this->getAggregateAlias($agg);
			
			if ($aggAlias === null) {
				return false;
			}
			
			// Mark LEFT JOINs that don't contribute to the final result set as excluded
			// This prevents them from affecting the window function transformation
			$this->markInertLeftJoinsAsExcluded($root);
			
			// Get all table aliases that are actually used in the query after
			// excluding inert joins (aliases that contribute columns, filters, etc.)
			$activeAliases = $this->getActiveAliases($root);
			
			// Verify that only one table alias is active and it matches the aggregate's alias
			// Window functions can only flatten when operating on a single table context
			return $this->validateSingleActiveAlias($activeAliases, $aggAlias);
		}
		
		/**
		 * Check basic conditions that would prevent window function usage.
		 *
		 * Validates three fundamental requirements for window function conversion:
		 * 1. No aggregate-level conditions (WHERE clauses, FILTER expressions)
		 * 2. No DISTINCT-like variants (SUMU/AVGU/COUNTU not supported as window functions)
		 * 3. Database engine must support window functions
		 *
		 * @param AstInterface $agg The aggregate node to validate
		 * @return bool True if all basic checks pass, false if any condition prevents window usage
		 */
		private function passesBasicWindowChecks(AstInterface $agg): bool {
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
			
			return true;
		}
		
		/**
		 * Extract the single alias that the aggregate references, or null if invalid.
		 *
		 * For window function conversion, an aggregate must reference exactly one table alias.
		 * This method collects all identifiers from the aggregate AST, extracts their range names,
		 * and validates that only one unique alias is referenced.
		 *
		 * @param AstInterface $agg The aggregate node to analyze for alias references
		 * @return string|null The single alias name if valid, null if zero or multiple aliases found
		 */
		private function getAggregateAlias(AstInterface $agg): ?string {
			$ids = $this->astUtilities->collectIdentifiersFromAst($this->getIdentifierIfAny($agg));
			$names = array_values(array_unique(array_map(static fn($id) => $id->getRange()->getName(), $ids)));
			
			// Aggregate must reference exactly one alias
			if (count($names) !== 1) {
				return null;
			}
			
			return $names[0];
		}
		
		/**
		 * Mark inert LEFT JOINs (only used in their own ON clause) as excluded.
		 *
		 * This method has a side effect: it modifies join ranges by setting includeAsJoin=false
		 * for LEFT JOINs that are only referenced in their own ON clause predicate. Such joins
		 * are considered "inert" because they don't affect the result set and can be safely
		 * excluded from window function queries.
		 *
		 * Only processes optional joins (non-required) that are currently included.
		 *
		 * @param AstRetrieve $root The root AST node containing all ranges to analyze
		 * @return void This method modifies ranges in-place via setIncludeAsJoin()
		 *
		 * @see isInertLeftJoin() For the logic determining if a join is inert
		 * @see shouldIncludeJoinRange() For current inclusion status
		 */
		private function markInertLeftJoinsAsExcluded(AstRetrieve $root): void {
			foreach ($root->getRanges() as $r) {
				$isMain = !method_exists($r, 'getJoinProperty') || $r->getJoinProperty() === null;
				if ($isMain) {
					continue;
				}
				
				// Skip required joins
				if (method_exists($r, 'isRequired') && $r->isRequired()) {
					continue;
				}
				
				// Current include flag (default true)
				$include = $this->shouldIncludeJoinRange($r);
				
				if ($include && $this->isInertLeftJoin($root, $r)) {
					if (method_exists($r, 'setIncludeAsJoin')) {
						$r->setIncludeAsJoin(false);
					}
				}
			}
		}
		
		/**
		 * Check if a join range should be included based on its current flags.
		 *
		 * Checks multiple possible method names for inclusion flags, as different range
		 * implementations may use different naming conventions. Falls back to true
		 * (include by default) if no explicit flag methods are found.
		 *
		 * @param mixed $r The join range object to check (various range implementations)
		 * @return bool True if the range should be included in the join, false otherwise
		 */
		private function shouldIncludeJoinRange($r): bool {
			if (method_exists($r, 'shouldIncludeAsJoin')) {
				return (bool)$r->shouldIncludeAsJoin();
			}
			
			if (method_exists($r, 'getIncludeAsJoin')) {
				return (bool)$r->getIncludeAsJoin();
			}
			
			// Default true
			return true;
		}
		
		/**
		 * Check if a join range is inert (only used in its own ON clause).
		 *
		 * An "inert" LEFT JOIN is one where the joined table's alias is only referenced
		 * within the join's own ON predicate and nowhere else in the query. Such joins
		 * don't affect the result set and can be safely omitted.
		 *
		 * @param AstRetrieve $root The root AST node to search for alias usage
		 * @param mixed $r The join range to evaluate for inertness
		 * @return bool True if the join is inert (safe to exclude), false otherwise
		 *
		 * @see countAliasOutsideOwnJoin() For the traversal logic counting alias usage
		 */
		private function isInertLeftJoin(AstRetrieve $root, $r): bool {
			$joinProp = method_exists($r, 'getJoinProperty') ? $r->getJoinProperty() : null;
			$alias = $r->getName();
			$outerUse = $this->countAliasOutsideOwnJoin($root, $alias, $joinProp);
			
			return $outerUse === 0;
		}
		
		/**
		 * Count how many times an alias is used outside its own JOIN ON clause.
		 *
		 * Performs a depth-first traversal of the AST, counting occurrences of identifiers
		 * that reference the specified alias. Excludes:
		 * - The join's own ON predicate subtree (if ownJoinProp provided)
		 * - Any subquery nodes (doesn't descend into them)
		 *
		 * This is used to determine if a LEFT JOIN is "inert" - if the count is 0,
		 * the join only appears in its own ON clause and can be safely excluded.
		 *
		 * @param AstRetrieve $tree The root AST node to traverse
		 * @param string $alias The alias name to count occurrences of
		 * @param mixed|null $ownJoinProp The join's ON predicate subtree to exclude from counting
		 * @return int Number of times the alias appears outside its own join predicate
		 */
		private function countAliasOutsideOwnJoin(AstRetrieve $tree, string $alias, $ownJoinProp = null): int {
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
		}
		
		/**
		 * Get all currently active aliases (main range + included joins).
		 *
		 * Builds a map of alias names that are considered "active" in the query context:
		 * - Main range (table) is always active
		 * - Required joins are always active regardless of include flags
		 * - Optional joins are active only if their include flags are true
		 *
		 * This is used to ensure window function conversion only occurs when exactly
		 * one range is active and matches the aggregate's referenced alias.
		 *
		 * @param AstRetrieve $root The root AST node containing all ranges to analyze
		 * @return array<string, true> Associative array mapping active alias names to true
		 *
		 * @see shouldIncludeJoinRange() For determining join inclusion status
		 */
		private function getActiveAliases(AstRetrieve $root): array {
			$activeAliases = [];
			
			foreach ($root->getRanges() as $r) {
				$isMain = !method_exists($r, 'getJoinProperty') || $r->getJoinProperty() === null;
				
				if ($isMain) {
					$activeAliases[$r->getName()] = true;
					continue;
				}
				
				// Required joins are always active
				if (method_exists($r, 'isRequired') && $r->isRequired()) {
					$activeAliases[$r->getName()] = true;
					continue;
				}
				
				// Check current include flag
				if ($this->shouldIncludeJoinRange($r)) {
					$activeAliases[$r->getName()] = true;
				}
			}
			
			return $activeAliases;
		}
		
		/**
		 * Validate that there's exactly one active alias and it matches the aggregate's alias.
		 *
		 * For window function conversion to be valid, the query must have exactly one
		 * active table/range, and the aggregate must reference that same range. This
		 * ensures the window function will operate over the correct data set.
		 *
		 * @param array<string, true> $activeAliases Map of currently active alias names
		 * @param string $aggAlias The alias that the aggregate references
		 * @return bool True if exactly one active alias exists and matches aggAlias
		 */
		private function validateSingleActiveAlias(array $activeAliases, string $aggAlias): bool {
			// There must be exactly one active alias
			if (count($activeAliases) !== 1) {
				return false;
			}
			
			// And it must be the one the aggregate references
			$onlyActive = array_key_first($activeAliases);
			return $onlyActive === $aggAlias;
		}
	}