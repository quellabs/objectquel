<?php
	
	namespace Quellabs\ObjectQuel\Execution\Joins;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\QueryManagement\ConditionEvaluator;
	
	/**
	 * Implements inner join between two result sets based on join conditions
	 *
	 * An inner join returns only the rows where the join condition is satisfied
	 * between the left and right result sets. No rows are returned if no matches exist.
	 */
	class InnerJoinStrategy implements JoinStrategyInterface {

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
		 * Performs an inner join between two result sets based on join conditions
		 * @param array $leftResult The left result set (array of associative arrays)
		 * @param array $rightResult The right result set (array of associative arrays)
		 * @param AstInterface|null $conditions Join conditions to evaluate
		 * @return array The joined result set containing only matching rows
		 * @throws QuelException When conditions are required but not provided or evaluation fails
		 */
		public function join(array $leftResult, array $rightResult, ?AstInterface $conditions = null): array {
			if ($conditions === null) {
				throw new QuelException('Inner join requires join conditions');
			}
			
			// Handle empty result sets - inner join returns empty if either side is empty
			if (empty($leftResult) || empty($rightResult)) {
				return [];
			}
			
			return $this->performInnerJoin($leftResult, $rightResult, $conditions);
		}
		
		/**
		 * Get the join type identifier
		 * @return string The join type
		 */
		public function getJoinType(): string {
			return 'inner';
		}
		
		/**
		 * Indicates whether this join strategy requires conditions
		 * @return bool True, as inner joins require conditions
		 */
		public function requiresConditions(): bool {
			return true;
		}
		
		/**
		 * Performs the actual inner join operation
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @param AstInterface $conditions The join conditions
		 * @return array The joined result set containing only matching rows
		 * @throws QuelException When condition evaluation fails
		 */
		private function performInnerJoin(array $leftResult, array $rightResult, AstInterface $conditions): array {
			$combined = [];
			
			foreach ($leftResult as $leftRow) {
				foreach ($rightResult as $rightRow) {
					$candidateRow = array_merge($leftRow, $rightRow);
					
					try {
						if ($this->conditionEvaluator->evaluate($conditions, $candidateRow)) {
							$combined[] = $candidateRow;
						}
					} catch (\Exception $e) {
						throw new QuelException(
							"Failed to evaluate join condition: " . $e->getMessage(),
							0,
							$e
						);
					}
				}
			}
			
			return $combined;
		}
	}