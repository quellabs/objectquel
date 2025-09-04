<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Execution\Support\BinaryOperationHelper;
	
	/**
	 * Processes EXISTS operators by removing them from conditions and converting
	 * them to required ranges (INNER JOINs) for better performance.
	 */
	class ExistsOptimizer {		
		
		/**
		 * Main entry point for optimization. Extracts EXISTS operators from the query
		 * conditions and converts them to required ranges for performance improvement.
		 * @param AstRetrieve $ast The retrieve AST node to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Fetch the conditions
			$conditions = $ast->getConditions();
			
			// Early return if no conditions to process
			if ($conditions === null) {
				return;
			}
			
			// Extract all EXISTS operators from the condition tree
			$existsList = $this->extractExistsOperators($ast, $conditions);
			
			// Convert each EXISTS to a required range (INNER JOIN)
			foreach ($existsList as $exists) {
				$this->setRangeRequiredForExists($ast, $exists);
			}
		}
		
		/**
		 * Sets the range as required for an EXISTS operation.
		 * This converts EXISTS subqueries into more efficient JOINs.
		 * @param AstRetrieve $ast The main query AST
		 * @param AstExists $exists The EXISTS operator to convert
		 */
		private function setRangeRequiredForExists(AstRetrieve $ast, AstExists $exists): void {
			$existsRange = $exists->getIdentifier()->getRange();
			
			// Find the matching range in the main query and mark it as required
			foreach ($ast->getRanges() as $range) {
				if ($range->getName() === $existsRange->getName()) {
					$range->setRequired();
					break;
				}
			}
		}
		
		/**
		 * Extracts EXISTS operators from conditions and handles different scenarios.
		 * This is the main dispatcher that handles both simple EXISTS and complex
		 * binary operation trees containing EXISTS operators.
		 * @param AstRetrieve $ast The main query AST (used for setting conditions)
		 * @param AstInterface $conditions The condition tree to process
		 * @return array Array of extracted AstExists objects
		 */
		private function extractExistsOperators(AstRetrieve $ast, AstInterface $conditions): array {
			// Simple case: single EXISTS operator
			if ($conditions instanceof AstExists) {
				$ast->setConditions(null);
				return [$conditions];
			}
			
			// Complex case: binary operation tree potentially containing EXISTS
			if ($conditions instanceof AstBinaryOperator) {
				$existsList = [];
				$this->extractExistsFromBinaryOperator($ast, $conditions, $existsList);
				return $existsList;
			}
			
			// No EXISTS operators found
			return [];
		}
		
		/**
		 * Recursively extracts EXISTS operators from binary operations.
		 * This method traverses the binary operation tree, finds EXISTS operators,
		 * extracts them into a list, and reconstructs the tree without them.
		 * @param AstInterface|null $parent The parent node (null for root)
		 * @param AstInterface $item Current node being processed
		 * @param array &$list Reference to list collecting EXISTS operators
		 * @param bool $parentLeft Whether current item is left child of parent
		 */
		private function extractExistsFromBinaryOperator(
			?AstInterface $parent,
			AstInterface $item,
			array &$list,
			bool $parentLeft = false
		): void {
			// Only process binary operation nodes
			if (!BinaryOperationHelper::isBinaryOperationNode($item)) {
				return;
			}
			
			$left = BinaryOperationHelper::getBinaryLeft($item);
			$right = BinaryOperationHelper::getBinaryRight($item);
			
			// Recursively process left branch if it's a binary operator
			if ($left instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $left, $list, true);
			}
			
			// Recursively process right branch if it's a binary operator
			if ($right instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $right, $list, false);
			}
			
			// Refresh left/right references after potential modifications from recursion
			$left = BinaryOperationHelper::getBinaryLeft($item);
			$right = BinaryOperationHelper::getBinaryRight($item);
			
			// Special case: both operands are EXISTS and this is the root condition
			// In this case, we remove the entire condition tree
			if ($parent instanceof AstRetrieve && $left instanceof AstExists && $right instanceof AstExists) {
				$list[] = $left;
				$list[] = $right;
				$parent->setConditions(null);
				return;
			}
			
			// Handle EXISTS in left operand: extract it and replace with right operand
			if ($left instanceof AstExists) {
				$list[] = $left;
				$this->setChildInParent($parent, $right, $parentLeft);
			}
			
			// Handle EXISTS in right operand: extract it and replace with left operand
			if ($right instanceof AstExists) {
				$list[] = $right;
				$this->setChildInParent($parent, $left, $parentLeft);
			}
		}
		
		/**
		 * Sets the appropriate child relationship between parent and item nodes.
		 * This method handles the tree restructuring after removing EXISTS operators.
		 * @param AstInterface|null $parent The parent node (null or AstRetrieve for root)
		 * @param AstInterface $item The node to set as child
		 * @param bool $parentLeft Whether to set as left child (true) or right child (false)
		 */
		private function setChildInParent(?AstInterface $parent, AstInterface $item, bool $parentLeft): void {
			// If parent is the root AstRetrieve, set as main condition
			if ($parent instanceof AstRetrieve) {
				$parent->setConditions($item);
				return;
			}
			
			// Only set child relationships for binary operation nodes
			if (!BinaryOperationHelper::isBinaryOperationNode($parent)) {
				return;
			}
			
			// Set as left or right child based on the parentLeft flag
			if ($parentLeft) {
				BinaryOperationHelper::setBinaryLeft($parent, $item);
			} else {
				BinaryOperationHelper::setBinaryRight($parent, $item);
			}
		}
	}