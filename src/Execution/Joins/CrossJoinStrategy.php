<?php
	
	namespace Quellabs\ObjectQuel\Execution\Joins;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Implements cross join (Cartesian product) between two result sets
	 *
	 * A cross join creates a Cartesian product where each row from the left result set
	 * is combined with every row from the right result set, regardless of any conditions.
	 */
	class CrossJoinStrategy implements JoinStrategyInterface {
		
		/**
		 * Performs a cross join between two result sets
		 * @param array $leftResult The left result set (array of associative arrays)
		 * @param array $rightResult The right result set (array of associative arrays)
		 * @param AstInterface|null $conditions Join conditions (ignored for cross joins)
		 * @return array The combined result set containing all possible combinations
		 */
		public function join(array $leftResult, array $rightResult, ?AstInterface $conditions = null): array {
			// Cross joins ignore conditions, so we don't use the $conditions parameter
			
			// Handle empty result sets
			if (empty($leftResult) && empty($rightResult)) {
				return [];
			}
			
			// If left is empty but right has data, return right result
			if (empty($leftResult) && !empty($rightResult)) {
				return $rightResult;
			}
			
			// If right is empty but left has data, return left result
			if (empty($rightResult) && !empty($leftResult)) {
				return $leftResult;
			}
			
			return $this->performCartesianProduct($leftResult, $rightResult);
		}
		
		/**
		 * Get the join type identifier
		 * @return string The join type
		 */
		public function getJoinType(): string {
			return 'cross';
		}
		
		/**
		 * Indicates whether this join strategy requires conditions
		 * @return bool False, as cross joins don't use conditions
		 */
		public function requiresConditions(): bool {
			return false;
		}
		
		/**
		 * Performs the actual Cartesian product operation
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @return array The combined result set
		 */
		private function performCartesianProduct(array $leftResult, array $rightResult): array {
			$combined = [];
			
			foreach ($leftResult as $leftRow) {
				foreach ($rightResult as $rightRow) {
					// Merge the rows, with right row values taking precedence for duplicate keys
					$combined[] = array_merge($leftRow, $rightRow);
				}
			}
			
			return $combined;
		}
	}