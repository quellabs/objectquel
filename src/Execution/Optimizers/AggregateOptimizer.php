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
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	
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
		private AstUtilities $astUtilities;
		private AstNodeReplacer $astNodeReplacer;
		
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
			$this->astNodeReplacer = new AstNodeReplacer();
		}
		
		/**
		 * Main optimization entry point - processes entire AST for aggregate functions
		 * @param AstRetrieve $ast Root query AST node to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Get all aggregates in one pass
			$aggregates = $this->findAllAggregateNodes($ast);
			
			// Determine strategy for each
			foreach ($aggregates as $aggregate) {
				switch($this->determineStrategy($ast, $aggregate)) {
					case 'DIRECT' :
						// Keep aggregate in main query
						// Add GROUP BY if needed (mixed agg + non-agg)
						
						/*
						if ($this->needsGroupBy($ast)) {
							$this->addGroupByClause($ast);
						}
						*/
						break;
					
					case 'SUBQUERY':
						$this->convertToSubquery($ast, $aggregate);
						break;
					
					case 'WINDOW':
						$this->convertToWindowFunction($aggregate);
						break;
				}
			}
		}
		
		private function convertToSubquery(AstRetrieve $ast, AstInterface $aggregate): void {
			// 1. Find which ranges the aggregate uses
			$aggregateRanges = $this->getRangesUsedInExpression($aggregate);
			
			// 2. Get all available ranges from the main query
			$allRanges = $ast->getRanges();
			
			// 3. Find the minimal set of ranges needed for the subquery
			$requiredRanges = $this->findMinimalRangeSet($allRanges, $aggregateRanges);
			
			// 4. Clone ranges to avoid mutating the original query
			$subqueryRanges = array_map(fn($r) => $r->deepClone(), $requiredRanges);
			
			// 5. Extract aggregate's conditions for subquery WHERE
			$subqueryWhere = $aggregate->getConditions();
			
			// 6. Create clean aggregate without embedded conditions
			$cleanAggregate = $this->deepCloneAggregateWithoutConditions($aggregate);
			
			// 7. Create and replace with subquery
			$subquery = new AstSubquery(
				AstSubquery::TYPE_SCALAR,
				$cleanAggregate,
				$subqueryRanges,
				$subqueryWhere
			);
			
			$this->astNodeReplacer->replaceChild($aggregate->getParent(), $aggregate, $subquery);
		}
		
		private function convertToWindowFunction(AstInterface $aggregate): void {
			// Create a window function subquery wrapping the clean aggregate
			$cleanAggregate = $this->deepCloneAggregateWithoutConditions($aggregate);
			
			$windowFunction = new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$cleanAggregate,
				[],      // No ranges needed - window operates on result set
				null     // No WHERE clause - conditions stay in outer query
			);
			
			// Replace the original aggregate with the window function
			$this->astNodeReplacer->replaceChild($aggregate->getParent(), $aggregate, $windowFunction);
		}
		
		private function deepCloneAggregateWithoutConditions(AstInterface $aggregate): AstInterface {
			// Create a deep clone of the aggregate node
			$clone = $aggregate->deepClone();
			$clone->setConditions(null);
			return $clone;
		}
		
		private function findMinimalRangeSet(array $allRanges, array $aggregateRanges): array {
			$required = [];
			$processed = [];
			
			// Start with ranges directly used by the aggregate
			foreach ($aggregateRanges as $range) {
				$this->addRangeWithDependencies($range, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		private function addRangeWithDependencies(AstRange $range, array $allRanges, array &$required, array &$processed): void {
			// Avoid infinite loops
			if (in_array($range, $processed, true)) {
				return;
			}
			
			$processed[] = $range;
			
			// Add the range itself
			if (!in_array($range, $required, true)) {
				$required[] = $range;
			}
			
			// If this range has a join condition, find what it joins to
			if ($range->getJoinProperty()) {
				$joinCondition = $range->getJoinProperty();
				$referencedRanges = $this->getRangesUsedInExpression($joinCondition);
				
				foreach ($referencedRanges as $referencedRange) {
					if ($referencedRange !== $range) { // Don't self-reference
						$this->addRangeWithDependencies($referencedRange, $allRanges, $required, $processed);
					}
				}
			}
		}
		
		private function findAllAggregateNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes($this->aggregateTypes);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		private function determineStrategy(AstRetrieve $ast, AstInterface $aggregate): string {
			// Rule 0: Aggregate has conditions = must be subquery (highest priority)
			if ($aggregate->getConditions() !== null) {
				error_log("Strategy: SUBQUERY (has conditions)");
				return 'SUBQUERY';
			}
			
			// Rule 1: Pure aggregation query (only aggregates in SELECT)
			if ($this->isOnlyAggregatesInSelect($ast)) {
				error_log("Strategy: DIRECT (pure aggregation)");
				return 'DIRECT';
			}
			
			// Rule 2: Mixed SELECT with aggregates + non-aggregates
			$nonAggregates = $this->getNonAggregateSelectItems($ast);
			
			if (!empty($nonAggregates)) {
				$aggRanges = $this->getRangesUsedInExpression($aggregate);
				$nonAggRanges = $this->getRangesUsedInExpressions($nonAggregates);
				
				// DEBUG: Log what ranges are found
				error_log("Agg ranges: " . count($aggRanges));
				error_log("Non-agg ranges: " . count($nonAggRanges));
				error_log("Has overlap: " . ($this->hasRangeOverlap($aggRanges, $nonAggRanges) ? 'YES' : 'NO'));
				
				if ($this->hasRangeOverlap($aggRanges, $nonAggRanges)) {
					error_log("Strategy: DIRECT (overlapping ranges)");
					return 'DIRECT';
				}
				
				if ($this->areRangesDisjoint($aggRanges, $nonAggRanges)) {
					error_log("Strategy: SUBQUERY (disjoint ranges)");
					return 'SUBQUERY';
				}
			}
			
			// Rule 3: Window function candidate
			if ($this->canBeWindowFunction($ast, $aggregate)) {
				error_log("Strategy: WINDOW (single table, no conditions)");
				return 'WINDOW';
			}
			
			// Default fallback
			error_log("Strategy: SUBQUERY (fallback)");
			return 'SUBQUERY';
		}
		
		private function getNonAggregateSelectItems(AstRetrieve $retrieve): array {
			$result = [];
			
			foreach($retrieve->getValues() as $value) {
				if (
					!$value->getExpression() instanceof AstSum &&
					!$value->getExpression() instanceof AstSumU &&
					!$value->getExpression() instanceof AstAvg &&
					!$value->getExpression() instanceof AstAvgU &&
					!$value->getExpression() instanceof AstCount &&
					!$value->getExpression() instanceof AstCountU &&
					!$value->getExpression() instanceof AstMax &&
					!$value->getExpression() instanceof AstMin
				) {
					$result[] = $value;
				}
			}
			
			return $result;
		}
		
		private function isOnlyAggregatesInSelect(AstRetrieve $retrieve): bool {
			foreach($retrieve->getValues() as $value) {
				if (!$this->isAggregateExpression($value->getExpression())) {
					return false;
				}
			}
			
			return true;
		}
		
		private function isAggregateExpression(AstInterface $expression): bool {
			return in_array(get_class($expression), $this->aggregateTypes, true);
		}
		
		private function getRangesUsedInExpression(AstInterface $expression): array {
			$visitor = new CollectRanges();
			$expression->accept($visitor);
			return $visitor->getCollectedNodes();
		}

		private function getRangesUsedInExpressions(array $expressions): array {
			$visitor = new CollectRanges();
			
			foreach($expressions as $expression) {
				$expression->accept($visitor);
			}

			return $visitor->getCollectedNodes();
		}
		
		private function hasRangeOverlap(array $aggRanges, array $nonAggRanges): bool {
			// Direct overlap: same range objects used in both
			foreach ($aggRanges as $aggRange) {
				foreach ($nonAggRanges as $nonAggRange) {
					if ($aggRange === $nonAggRange) {
						error_log("Direct overlap found");
						return true;
					}
				}
			}
			
			// Related ranges: connected via joins
			$related = $this->areRangesRelated($aggRanges, $nonAggRanges);
			error_log("Ranges related: " . ($related ? 'YES' : 'NO'));
			
			return $related;
		}
		
		private function areRangesDisjoint(array $aggRanges, array $nonAggRanges): bool {
			// If they overlap or are related, they're NOT disjoint
			return !$this->hasRangeOverlap($aggRanges, $nonAggRanges);
		}
		
		private function areRangesRelated(array $ranges1, array $ranges2): bool {
			foreach ($ranges1 as $range1) {
				foreach ($ranges2 as $range2) {
					error_log("Checking if ranges are joined: " . get_class($range1) . " vs " . get_class($range2));
					
					if ($this->rangesAreJoined($range1, $range2)) {
						error_log("Ranges ARE joined");
						return true;
					}
				}
			}
			error_log("No joined ranges found");
			return false;
		}
		
		private function rangesAreJoined(AstRange $range1, AstRange $range2): bool {
			// Check if range2 joins to range1
			$joinCondition = $range2->getJoinProperty();
			
			if ($joinCondition && $this->joinReferences($joinCondition, $range1)) {
				return true;
			}
			
			// Check reverse direction
			$joinCondition = $range1->getJoinProperty();
			
			if ($joinCondition && $this->joinReferences($joinCondition, $range2)) {
				return true;
			}
			
			return false;
		}
		
		private function joinReferences(AstInterface $joinCondition, AstRange $targetRange): bool {
			// Get all identifiers used in the join condition
			$identifiers = $this->astUtilities->collectIdentifiersFromAst($joinCondition);
			
			// Check if any identifier belongs to the target range
			foreach ($identifiers as $identifier) {
				if ($identifier->getRange() === $targetRange) {
					return true;
				}
			}
			
			return false;
		}
		
		private function canBeWindowFunction(AstRetrieve $ast, AstInterface $aggregate): bool {
			// 1. Basic compatibility checks
			if (!$this->passesBasicWindowChecks($aggregate)) {
				return false;
			}
			
			// 2. Must be a single-table query (no meaningful JOINs for window context)
			$ranges = $ast->getRanges();
			
			if (count($ranges) !== 1) {
				return false;
			}
			
			// 3. The aggregate must reference the same single range
			$aggRanges = $this->getRangesUsedInExpression($aggregate);
			
			if (count($aggRanges) !== 1 || $aggRanges[0] !== $ranges[0]) {
				return false;
			}
			
			// 4. All SELECT items must reference the same range (no cross-table mixing)
			$selectItems = $ast->getValues();
			
			foreach ($selectItems as $item) {
				if ($item->getExpression() === $aggregate) {
					continue;
				}
				
				$itemRanges = $this->getRangesUsedInExpression($item);
				
				if (count($itemRanges) !== 1 || $itemRanges[0] !== $ranges[0]) {
					return false;
				}
			}
			
			return true;
		}
		
		private function passesBasicWindowChecks(AstInterface $aggregate): bool {
			// Check 1: No aggregate-level conditions (WHERE/HAVING clauses)
			// Window functions can't have their own filtering conditions
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// Check 2: No DISTINCT variants (SUMU, COUNTU, AVGU)
			// Most databases don't support DISTINCT in window function context
			if (in_array(get_class($aggregate), $this->distinctClasses, true)) {
				return false;
			}
			
			// Check 3: Database must support window functions
			// Older MySQL versions, some SQLite configurations don't support them
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false;
			}
			
			// Check 4: Must be a supported aggregate type for window functions
			// Some custom aggregates might not work as window functions
			if (!$this->isSupportedWindowAggregate($aggregate)) {
				return false;
			}
			
			return true;
		}
		
		private function isSupportedWindowAggregate(AstInterface $aggregate): bool {
			// Standard aggregates that work well as window functions
			$supportedTypes = [
				AstSum::class,
				AstCount::class,
				AstAvg::class,
				AstMin::class,
				AstMax::class,
			];
			
			return in_array(get_class($aggregate), $supportedTypes, true);
		}
	}