<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	
	/**
	 * Optimizes JOIN types based on WHERE clause analysis.
	 * Converts LEFT JOINs to INNER JOINs when safe, and vice versa.
	 */
	class JoinOptimizer {
		
		/**
		 * Entity metadata store for field nullability checks
		 */
		private EntityStore $entityStore;
		
		/**
		 * Initialize optimizer with entity metadata access
		 *
		 * @param EntityManager $entityManager Manager providing entity metadata
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
		}
		
		/**
		 * Main optimization entry point - analyzes all ranges in the AST
		 *
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
		 * 1. NULL Check Priority: If range has NULL checks → Keep as LEFT JOIN
		 * 2. Field Reference Analysis: Check if references are safe to convert
		 * 3. Nullability-Based Conversion: Convert only when fields are non-nullable
		 *
		 * @param AstRetrieve $ast The complete query AST
		 * @param AstRange $range The specific table range to analyze
		 */
		private function analyzeRangeForJoinOptimization(AstRetrieve $ast, AstRange $range): void {
			// Check if WHERE clause contains explicit NULL checks for this range
			$hasNullChecks = $this->rangeHasNullChecks($ast, $range);
			
			// Check if WHERE clause references any fields from this range
			$hasFieldReferences = $this->conditionsListHasFieldReferences($ast, $range);
			
			// If currently INNER JOIN but has NULL checks, convert to LEFT JOIN
			// This preserves rows where the joined table has NULL values
			if ($range->isRequired() && $hasNullChecks) {
				$range->setRequired(false);
				return;
			}
			
			// If currently LEFT JOIN but has field references, consider INNER JOIN conversion
			if (!$range->isRequired() && $hasFieldReferences && !$hasNullChecks) {
				// Only convert if the referenced fields are non-nullable
				// Non-nullable field references effectively filter out NULL rows anyway
				$hasNonNullableReferences = $this->conditionListHasNonNullableReferences($ast, $range);
				
				if ($hasNonNullableReferences) {
					// Safe to convert LEFT JOIN to INNER JOIN - performance improvement
					$range->setRequired();
				}
			}
		}
		
		/**
		 * Uses visitor pattern to detect NULL checks for the specified range.
		 * Visitor throws exception when match is found (early termination pattern).
		 *
		 * @param AstRetrieve $ast The query AST to search
		 * @param AstRange $range The range to check for NULL conditions
		 * @return bool True if NULL checks exist for this range
		 */
		private function rangeHasNullChecks(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Visitor pattern with exception-based early termination
				$visitor = new ContainsCheckIsNullForRange($range->getName());
				$ast->getConditions()->accept($visitor);
				// If we get here, no NULL checks were found
				return false;
			} catch (\Exception $e) {
				// Exception indicates NULL check was found
				return true;
			}
		}
		
		/**
		 * Determines if fields from the specified range are referenced in WHERE clause.
		 *
		 * @param AstRetrieve $ast The query AST to search
		 * @param AstRange $range The range to check for field references
		 * @return bool True if any fields from this range are referenced
		 */
		private function conditionsListHasFieldReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Use visitor pattern to traverse condition tree
				$visitor = new ContainsRange($range->getName());
				$ast->getConditions()->accept($visitor);
				// No references found if we reach here
				return false;
			} catch (\Exception $e) {
				// Exception indicates field reference was found
				return true;
			}
		}
		
		/**
		 * Identifies whether WHERE conditions reference non-nullable fields.
		 * Non-nullable field references can affect join elimination.
		 * @param AstRetrieve $ast The query AST to analyze
		 * @param AstRange $range The range to check for non-nullable field usage
		 * @return bool True if non-nullable fields are referenced
		 */
		private function conditionListHasNonNullableReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Check field nullability using entity metadata
				$visitor = new ContainsNonNullableFieldForRange($range->getName(), $this->entityStore);
				$ast->getConditions()->accept($visitor);
				// No non-nullable references found
				return false;
			} catch (\Exception $e) {
				// Exception indicates non-nullable field reference was found
				return true;
			}
		}
	}