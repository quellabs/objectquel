<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeConditionWrapper;
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
		public static function replaceChild(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			// Set new parent
			$newChild->setParent($parent);
			
			// Handle binary operations (most common case).
			// NodeBinary is the structural interface for nodes with a left and right child
			// (AstExpression, AstBinaryOperator, AstTerm, AstFactor). The interface
			// declares both getters and setters, so no capability probing is needed.
			if ($parent instanceof NodeBinary) {
				self::replaceBinaryChild($parent, $oldChild, $newChild);
				return;
			}
			
			// Replace expression child (e.g., in NOT operations, NULL checks, aliases).
			// NodeConditionWrapper guarantees both getExpression() and setExpression(),
			// so we can replace unconditionally once the identity check passes.
			if ($parent instanceof NodeConditionWrapper && $parent->getExpression() === $oldChild) {
				$parent->setExpression($newChild);
				return;
			}
			
			// Replace conditions child (e.g., in WHERE clauses, aggregate filters).
			// Only AstRetrieve and AstAggregate declare setConditions(); AstSubquery has
			// getConditions() but no setter, so it is intentionally excluded here.
			if (($parent instanceof AstRetrieve || $parent instanceof AstAggregate) && $parent->getConditions() === $oldChild) {
				$parent->setConditions($newChild);
				return;
			}
			
			// Replace identifier child (e.g., the column reference inside an aggregate).
			// AstAggregate declares both getIdentifier() and setIdentifier(). AstIn only
			// has getIdentifier() with no setter, so testing the concrete abstract base
			// class is the correct boundary here.
			if ($parent instanceof AstAggregate && $parent->getIdentifier() === $oldChild) {
				$parent->setIdentifier($newChild);
				return;
			}
			
			// Replace aggregation child (e.g., the aggregate expression inside a subquery).
			// Only AstSubquery exposes getAggregation()/setAggregation(); the concrete
			// class check is intentional — no shared interface exists for this slot.
			if ($parent instanceof AstSubquery && $parent->getAggregation() === $oldChild) {
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
		 * Replaces a child in a binary operation node.
		 * Handles left and right child replacement for binary operators.
		 * @param AstInterface $parent The binary parent node
		 * @param AstInterface $oldChild The child node to replace
		 * @param AstInterface $newChild The replacement child node
		 * @throws \InvalidArgumentException When the old child is not found in either position
		 */
		public static function replaceBinaryChild(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			// NodeBinary guarantees getLeft/setLeft and getRight/setRight, so no
			// method_exists probing is needed — a plain instanceof suffices.
			if (!($parent instanceof NodeBinary)) {
				throw new \InvalidArgumentException('Parent is not a binary node');
			}
			
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