<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRangeTemporary;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\UsesRange;
	
	/**
	 * Optimizes JOIN types based on WHERE clause analysis.
	 * Converts LEFT JOINs to INNER JOINs when safe, and vice versa.
	 *
	 * Architectural assumptions:
	 * - AstRange::isRequired() === true means INNER JOIN; false means LEFT JOIN.
	 * - Visitors correctly reflect SQL semantics for NULL checks and field references.
	 * - Non-nullable field references in WHERE eliminate NULL rows, making LEFT JOIN
	 *   semantically equivalent to INNER JOIN.
	 */
	class JoinOptimizer {
		
		/**
		 * Entity metadata store for field nullability checks
		 */
		private EntityStore $entityStore;
		
		/**
		 * Initialize optimizer with entity metadata access
		 * @param EntityManager $entityManager Manager providing entity metadata
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
		}
		
		/**
		 * Main optimization entry point - analyzes all ranges in the AST.
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Skip optimization if no WHERE conditions exist
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Analyze each table/range reference for JOIN optimization opportunities
			foreach ($ast->getRanges() as $range) {
				$this->analyzeRangeForJoinOptimization($ast, $range);
			}
		}
		
		/**
		 * Analyzes a specific range to determine the optimal JOIN type.
		 *
		 * Decision logic:
		 * 1. NULL Check Priority: If range has NULL checks → force LEFT JOIN
		 * 2. Non-nullable Reference Promotion: LEFT JOIN → INNER JOIN when WHERE
		 *    references a non-nullable field (NULL rows are already filtered out)
		 *
		 * @param AstRetrieve $ast The complete query AST
		 * @param AstRange $range The specific table range to analyze
		 */
		private function analyzeRangeForJoinOptimization(AstRetrieve $ast, AstRange $range): void {
			$analysis = $this->analyzeConditions($ast, $range);
			
			if ($this->shouldConvertToLeftJoin($range, $analysis)) {
				$range->setRequired(false);
			} elseif ($this->shouldConvertToInnerJoin($range, $analysis)) {
				$range->setRequired(true);
			}
		}
		
		/**
		 * Performs a single-pass analysis of all condition signals for a range.
		 * Collects all needed information in one traversal per visitor type,
		 * avoiding redundant full-tree walks.
		 * @param AstRetrieve $ast The query AST to analyze
		 * @param AstRange $range The range to collect signals for
		 * @return RangeConditionAnalysis Collected condition signals
		 */
		private function analyzeConditions(AstRetrieve $ast, AstRange $range): RangeConditionAnalysis {
			// Check whether the WHERE clause contains IS NULL / IS NOT NULL for this range.
			// If so, the join must stay LEFT — converting to INNER would drop those rows.
			$nullCheckVisitor = new ContainsCheckIsNullForRange($range->getName());
			$this->runVisitor($ast, $nullCheckVisitor);
			
			// Check whether the WHERE clause references any field from this range at all.
			// No references means no basis for conversion in either direction.
			$usesRangeVisitor = new UsesRange($range->getName());
			$this->runVisitor($ast, $usesRangeVisitor);
			
			// Only run the nullability visitor when there are field references and no null checks,
			// since it's the most expensive and only relevant for LEFT → INNER conversion.
			// A non-nullable field reference in WHERE means any NULL row from the join is already
			// filtered out, so LEFT and INNER produce identical results — INNER is cheaper.
			$eliminatesNulls = false;
			
			if ($usesRangeVisitor->isFound() && !$nullCheckVisitor->isFound()) {
				$nullabilityVisitor = $this->buildNullabilityVisitor($range);
				$this->runVisitor($ast, $nullabilityVisitor);
				$eliminatesNulls = $nullabilityVisitor->isNonNullable();
			}
			
			return new RangeConditionAnalysis(
				hasNullChecks: $nullCheckVisitor->isFound(),
				hasFieldReferences: $usesRangeVisitor->isFound(),
				eliminatesNulls: $eliminatesNulls
			);
		}
		
		/**
		 * Determines whether an INNER JOIN should be converted to a LEFT JOIN.
		 * This is needed when the WHERE clause explicitly tests for NULL values
		 * from the joined table — a pattern used in NOT EXISTS / optional-join queries.
		 * @param AstRange $range The range being evaluated
		 * @param RangeConditionAnalysis $analysis Collected condition signals
		 * @return bool True if the range should be converted to LEFT JOIN
		 */
		private function shouldConvertToLeftJoin(AstRange $range, RangeConditionAnalysis $analysis): bool {
			return $range->isRequired() && $analysis->hasNullChecks;
		}
		
		/**
		 * Determines whether a LEFT JOIN can be safely promoted to an INNER JOIN.
		 * Safe when the WHERE clause references a non-nullable field from the joined
		 * table: any row where the join produces NULL will already be filtered out,
		 * so the LEFT/INNER distinction has no observable effect on the result set.
		 * @param AstRange $range The range being evaluated
		 * @param RangeConditionAnalysis $analysis Collected condition signals
		 * @return bool True if the range can be safely converted to INNER JOIN
		 */
		private function shouldConvertToInnerJoin(AstRange $range, RangeConditionAnalysis $analysis): bool {
			return !$range->isRequired()
				&& $analysis->hasFieldReferences
				&& !$analysis->hasNullChecks
				&& $analysis->eliminatesNulls;
		}
		
		/**
		 * Runs a visitor over the WHERE conditions of the given AST.
		 * Centralizes the $ast->getConditions()->accept() call pattern.
		 * Safe to call here because optimize() guards against null conditions.
		 * @param AstRetrieve $ast The query AST
		 * @param AstVisitorInterface $visitor The visitor to run
		 */
		private function runVisitor(AstRetrieve $ast, AstVisitorInterface $visitor): void {
			$ast->getConditions()->accept($visitor);
		}
		
		/**
		 * Constructs the appropriate nullability visitor for a range.
		 * Temporary ranges (subqueries) use a specialized visitor that inspects
		 * the subquery's own output columns rather than entity metadata.
		 * @param AstRange $range The range to build a visitor for
		 * @return ContainsNonNullableFieldForRange|ContainsNonNullableFieldForRangeTemporary
		 */
		private function buildNullabilityVisitor(AstRange $range): ContainsNonNullableFieldForRange|ContainsNonNullableFieldForRangeTemporary {
			if ($range instanceof AstRangeDatabase && $range->containsQuery()) {
				return new ContainsNonNullableFieldForRangeTemporary(
					$range->getName(),
					$range->getQuery(),
					$this->entityStore
				);
			}
			
			return new ContainsNonNullableFieldForRange($range->getName(), $this->entityStore);
		}
	}