<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Utility class for replacing nodes in the AST tree.
	 * Handles parent-child relationships and maintains tree integrity.
	 */
	class AstNodeReplacer {
		
		/**
		 * Replaces a child node in the parent with a new node.
		 * Maintains proper parent-child relationships in the AST.
		 * @param AstInterface $parent The parent node containing the child to replace
		 * @param AstInterface $oldChild The existing child node to be replaced
		 * @param AstInterface $newChild The new child node to replace the old one
		 * @throws \InvalidArgumentException When the old child cannot be found or replacement is not supported
		 */
		public function replaceChild(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			// Set new parent
			$newChild->setParent($parent);
			
			// Handle binary operations (most common case)
			// Binary nodes have left and right children (e.g., AND, OR, comparison operators)
			if ($this->isBinaryNode($parent)) {
				$this->replaceBinaryChild($parent, $oldChild, $newChild);
				return;
			}
			
			// Replace expression child (e.g., in NOT operations, function calls)
			if (method_exists($parent, 'getExpression') && $parent->getExpression() === $oldChild) {
				$parent->setExpression($newChild);
				return;
			}
			
			// Replace conditions child (e.g., in WHERE clauses, conditional blocks)
			if (method_exists($parent, 'getConditions') && $parent->getConditions() === $oldChild) {
				$parent->setConditions($newChild);
				return;
			}
			
			// Replace identifier child (e.g., in field references, variable names)
			if (method_exists($parent, 'getIdentifier') && $parent->getIdentifier() === $oldChild) {
				$parent->setIdentifier($newChild);
				return;
			}
			
			// Replace identifier child (e.g., in field references, variable names)
			if (method_exists($parent, 'getAggregation') && $parent->getAggregation() === $oldChild) {
				$parent->setAggregation($newChild);
				return;
			}
			
			// If we reach here, the parent-child relationship is not recognized
			throw new \InvalidArgumentException(
				sprintf(
					'Cannot replace child of type %s in parent of type %s',
					get_class($oldChild),
					get_class($parent)
				)
			);
		}
		
		/**
		 * Determines if a node is a binary operation node.
		 * Binary nodes have both left and right child properties.
		 * @param AstInterface $node The node to check
		 * @return bool True if the node has both getLeft and getRight methods
		 */
		private function isBinaryNode(AstInterface $node): bool {
			return
				$node instanceof AstFactor ||
				$node instanceof AstTerm ||
				$node instanceof AstExpression ||
				$node instanceof AstBinaryOperator;
		}
		
		/**
		 * Replaces a child in a binary operation node.
		 * Handles left and right child replacement for binary operators.
		 * @param AstInterface $parent The binary parent node
		 * @param AstInterface $oldChild The child node to replace
		 * @param AstInterface $newChild The replacement child node
		 * @throws \InvalidArgumentException When the old child is not found in either position
		 */
		private function replaceBinaryChild(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			// Check if old child is the left operand
			if ($parent->getLeft() === $oldChild) {
				$parent->setLeft($newChild);
				return;
			}
			
			// Check if old child is the right operand
			if ($parent->getRight() === $oldChild) {
				$parent->setRight($newChild);
				return;
			}
			
			// Old child not found in either position - this indicates a logic error
			throw new \InvalidArgumentException('Old child not found in parent binary node');
		}
	}