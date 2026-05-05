<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
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
			// Fetch the identifier from the exists node
			$identifier = $exists->getIdentifier();
			
			// Check that this is in fact an identifier
			if (!$identifier instanceof AstIdentifier) {
				return;
			}
			
			// Fetch the range from the identifier
			$existsRange = $identifier->getRange();
			
			// Check that the range is a database range.
			if (!$existsRange instanceof AstRangeDatabase) {
				return;
			}
			
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
		 * @return array<int, AstExists> Array of extracted AstExists objects
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
		 * @param array<int, AstExists> $list Reference to list collecting EXISTS operators
		 */
		private function extractExistsFromBinaryOperator(?AstInterface $parent, AstInterface $item, array &$list): void {
			// Only process binary operation nodes
			if (!BinaryOperationHelper::isBinaryOperationNode($item)) {
				return;
			}
			
			$left = BinaryOperationHelper::getBinaryLeft($item);
			$right = BinaryOperationHelper::getBinaryRight($item);
			
			// Recursively process left branch if it's a binary operator
			if ($left instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $left, $list);
			}
			
			// Recursively process right branch if it's a binary operator
			if ($right instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $right, $list);
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
				$this->setChildInParent($parent, $left, $right);
			}
			
			// Handle EXISTS in right operand: extract it and replace with left operand
			if ($right instanceof AstExists) {
				$list[] = $right;
				$this->setChildInParent($parent, $right, $left);
			}
		}
		
		/**
		 * Replaces an EXISTS operator in the parent node with its sibling node.
		 * Determines the correct slot (left/right) by comparing the EXISTS node
		 * identity against the parent's current children.
		 * @param AstInterface|null $parent The parent node, or null if there is no parent
		 * @param AstExists $exists The EXISTS operator being removed
		 * @param AstInterface $replacement The sibling node that will take the EXISTS operator's place
		 */
		private function setChildInParent(?AstInterface $parent, AstExists $exists, AstInterface $replacement): void {
			// If parent is the root AstRetrieve, replace the entire condition with the sibling
			if ($parent instanceof AstRetrieve) {
				$parent->setConditions($replacement);
				return;
			}
			
			// Null parent or non-binary nodes cannot have children set
			if ($parent === null || !BinaryOperationHelper::isBinaryOperationNode($parent)) {
				return;
			}
			
			// Determine which slot the EXISTS occupied by identity comparison,
			// then put the sibling in its place
			if (BinaryOperationHelper::getBinaryLeft($parent) === $exists) {
				BinaryOperationHelper::setBinaryLeft($parent, $replacement);
			} else {
				BinaryOperationHelper::setBinaryRight($parent, $replacement);
			}
		}
	}