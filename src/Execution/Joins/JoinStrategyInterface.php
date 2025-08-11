<?php
	
	namespace Quellabs\ObjectQuel\Execution\Joins;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Defines the contract that all join strategies must implement to be
	 * compatible with the PlanExecutor's result combination system.
	 */
	interface JoinStrategyInterface {

		/**
		 * Performs a join between two result sets
		 * @param array $leftResult The left result set (array of associative arrays)
		 * @param array $rightResult The right result set (array of associative arrays)
		 * @param AstInterface|null $conditions Join conditions (may be null for some join types)
		 * @return array The joined result set
		 * @throws QuelException When the join operation fails
		 */
		public function join(array $leftResult, array $rightResult, ?AstInterface $conditions = null): array;
		
		/**
		 * Get the join type identifier
		 * @return string The join type (e.g., 'inner', 'left', 'cross')
		 */
		public function getJoinType(): string;
		
		/**
		 * Indicates whether this join strategy requires conditions
		 * @return bool True if conditions are required, false otherwise
		 */
		public function requiresConditions(): bool;
	}