<?php
	
	namespace Quellabs\ObjectQuel\Execution\Joins;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\QueryManagement\ConditionEvaluator;
	
	/**
	 * Implements left join between two result sets based on join conditions
	 *
	 * A left join returns all rows from the left result set, and matching rows from
	 * the right result set. When no match is found, NULL values are used for right-side columns.
	 */
	class LeftJoinStrategy implements JoinStrategyInterface {
		
		/**
		 * Condition evaluator used to evaluate join conditions
		 */
		private ConditionEvaluator $conditionEvaluator;
		
		/**
		 * @param ConditionEvaluator $conditionEvaluator Evaluator for join conditions
		 */
		public function __construct(ConditionEvaluator $conditionEvaluator) {
			$this->conditionEvaluator = $conditionEvaluator;
		}
		
		/**
		 * Performs a left join between two result sets based on join conditions
		 * @param array $leftResult The left result set (array of associative arrays)
		 * @param array $rightResult The right result set (array of associative arrays)
		 * @param AstInterface|null $conditions Join conditions to evaluate
		 * @return array The joined result set
		 * @throws QuelException When conditions are required but not provided
		 */
		public function join(array $leftResult, array $rightResult, ?AstInterface $conditions = null): array {
			if ($conditions === null) {
				throw new QuelException('Left join requires join conditions');
			}
			
			// Handle empty result sets
			if (empty($leftResult)) {
				return [];
			}
			
			if (empty($rightResult)) {
				return $this->addNullColumnsToLeftResult($leftResult);
			}
			
			return $this->performLeftJoin($leftResult, $rightResult, $conditions);
		}
		
		/**
		 * Get the join type identifier
		 * @return string The join type
		 */
		public function getJoinType(): string {
			return 'left';
		}
		
		/**
		 * Indicates whether this join strategy requires conditions
		 * @return bool True, as left joins require conditions
		 */
		public function requiresConditions(): bool {
			return true;
		}
		
		/**
		 * Performs the actual left join operation
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @param AstInterface $conditions The join conditions
		 * @return array The joined result set
		 * @throws QuelException When condition evaluation fails
		 */
		private function performLeftJoin(array $leftResult, array $rightResult, AstInterface $conditions): array {
			$combined = [];
			
			foreach ($leftResult as $leftRow) {
				$matchFound = false;
				
				// Check each right row for matches
				foreach ($rightResult as $rightRow) {
					$candidateRow = array_merge($leftRow, $rightRow);
					
					try {
						if ($this->conditionEvaluator->evaluate($conditions, $candidateRow)) {
							$combined[] = $candidateRow;
							$matchFound = true;
						}
					} catch (\Exception $e) {
						throw new QuelException(
							"Failed to evaluate join condition: " . $e->getMessage(),
							0,
							$e
						);
					}
				}
				
				// If no match found, add left row with null right columns
				if (!$matchFound) {
					$combined[] = $this->addNullColumnsToRow($leftRow, $rightResult);
				}
			}
			
			return $combined;
		}
		
		/**
		 * Adds null columns for all right-side columns when no right result exists
		 * @param array $leftResult The left result set
		 * @return array Left result with null placeholders for right columns
		 */
		private function addNullColumnsToLeftResult(array $leftResult): array {
			// Since there's no right result, we can't determine what columns to add
			// Return the left result as-is
			return $leftResult;
		}
		
		/**
		 * Adds null values for right-side columns to a left row
		 * @param array $leftRow The left row
		 * @param array $rightResult The right result set (used to determine column names)
		 * @return array Left row with null placeholders for right columns
		 */
		private function addNullColumnsToRow(array $leftRow, array $rightResult): array {
			if (empty($rightResult)) {
				return $leftRow;
			}
			
			// Get column names from the first right row
			$rightColumns = array_keys(reset($rightResult));
			$nullRightColumns = array_fill_keys($rightColumns, null);
			
			return array_merge($leftRow, $nullRightColumns);
		}
	}