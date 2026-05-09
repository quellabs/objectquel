<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DetectNullCheckOnRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DetectNonNullableField;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DetectNonNullableFieldInSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DetectRangeReference;
	
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
				// Analyzes a specific range to determine the optimal JOIN type.
				$analysis = $this->analyzeConditions($ast, $range);
				
				// NULL Check Priority: If range has NULL checks → force LEFT JOIN
				if ($analysis->hasNullChecks) {
					$range->setRequired(false);
					continue;
				}
				
				// Non-nullable Reference Promotion: LEFT JOIN → INNER JOIN when WHERE
				// references a non-nullable field (NULL rows are already filtered out)
				if ($analysis->hasFieldReferences && $analysis->eliminatesNulls) {
					$range->setRequired(true);
				}
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
			$nullCheckVisitor = new DetectNullCheckOnRange($range->getName());
			$this->runVisitor($ast, $nullCheckVisitor);
			
			// Check whether the WHERE clause references any field from this range at all.
			// No references means no basis for conversion in either direction.
			$usesRangeVisitor = new DetectRangeReference($range->getName());
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
		 * Runs a visitor over the WHERE conditions of the given AST.
		 * Centralizes the $ast->getConditions()->accept() call pattern.
		 * Safe to call here because optimize() guards against null conditions.
		 * @param AstRetrieve $ast The query AST
		 * @param AstVisitorInterface $visitor The visitor to run
		 */
		private function runVisitor(AstRetrieve $ast, AstVisitorInterface $visitor): void {
			$ast->getConditions()?->accept($visitor);
		}
		
		/**
		 * Constructs the appropriate nullability visitor for a range.
		 * Temporary ranges (subqueries) use a specialized visitor that inspects
		 * the subquery's own output columns rather than entity metadata.
		 * @param AstRange $range The range to build a visitor for
		 * @return DetectNonNullableField|DetectNonNullableFieldInSubquery
		 */
		private function buildNullabilityVisitor(AstRange $range): DetectNonNullableField|DetectNonNullableFieldInSubquery {
			// Database ranges can contain either a direct entity reference or a subquery
			if (!$range instanceof AstRangeDatabaseSubquery) {
				// Direct entity reference: nullability comes from entity metadata
				return new DetectNonNullableField($range->getName(), $this->entityStore);
			}
			
			// Fetch the query
			$query = $range->getQuery();
			
			// Subqueries produce a derived table, so nullability is determined
			// from the subquery's output columns rather than entity metadata
			return new DetectNonNullableFieldInSubquery(
				$range->getName(),
				$query,
				$this->entityStore
			);
		}
	}