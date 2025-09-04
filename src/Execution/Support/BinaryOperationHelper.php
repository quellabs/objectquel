<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Helper class for working with binary operations in the AST.
	 *
	 * This class provides a consistent interface for accessing left/right operands
	 * across different AST node types that support binary operations. It abstracts
	 * away the differences between various AST node implementations.
	 *
	 * IMPORTANT: This helper assumes that all supported node types implement
	 * getLeft(), getRight(), setLeft(), and setRight() methods, even though
	 * these methods are not defined in the AstInterface contract.
	 */
	class BinaryOperationHelper {
		
		/**
		 * Checks if the given AST node supports binary operations.
		 * A binary operation node is one that has both left and right operands,
		 * such as mathematical expressions (a + b), logical operations (a && b),
		 * comparison operators (a > b), etc.
		 * @param AstInterface|null $item The AST node to check
		 * @return bool True if the node supports binary operations, false otherwise
		 */
		public static function isBinaryOperationNode(?AstInterface $item): bool {
			// Null nodes cannot be binary operations
			if ($item === null) {
				return false;
			}
			
			// Check against known binary operation node types
			// These types are expected to have getLeft/getRight/setLeft/setRight methods
			return
				$item instanceof AstTerm ||
				$item instanceof AstBinaryOperator ||
				$item instanceof AstExpression ||
				$item instanceof AstFactor;
		}
		
		/**
		 * Retrieves the left operand from a binary operation node.
		 * @param AstInterface $item The binary operation node
		 * @return AstInterface The left operand
		 * @throws \InvalidArgumentException If the item doesn't support binary operations
		 * @throws \BadMethodCallException If the node doesn't actually implement getLeft()
		 */
		public static function getBinaryLeft(AstInterface $item): AstInterface {
			// Validate that this is a supported binary operation node type
			if (!self::isBinaryOperationNode($item)) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			// Call getLeft() - this may fail at runtime if the concrete class
			// doesn't implement this method, despite passing the type check above
			return $item->getLeft();
		}
		
		/**
		 * Retrieves the right operand from a binary operation node.
		 * @param AstInterface $item The binary operation node
		 * @return AstInterface The right operand
		 * @throws \InvalidArgumentException If the item doesn't support binary operations
		 * @throws \BadMethodCallException If the node doesn't actually implement getRight()
		 */
		public static function getBinaryRight(AstInterface $item): AstInterface {
			// Validate that this is a supported binary operation node type
			if (!self::isBinaryOperationNode($item)) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			// Call getRight() - this may fail at runtime if the concrete class
			// doesn't implement this method, despite passing the type check above
			return $item->getRight();
		}
		
		/**
		 * Sets the left operand of a binary operation node.
		 * @param AstInterface $item The binary operation node to modify
		 * @param AstInterface $left The new left operand
		 * @return void
		 * @throws \InvalidArgumentException If the item doesn't support binary operations
		 * @throws \BadMethodCallException If the node doesn't actually implement setLeft()
		 */
		public static function setBinaryLeft(AstInterface $item, AstInterface $left): void {
			// Validate that this is a supported binary operation node type
			if (!self::isBinaryOperationNode($item)) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			// Call setLeft() - this may fail at runtime if the concrete class
			// doesn't implement this method, despite passing the type check above
			$item->setLeft($left);
		}
		
		/**
		 * Sets the right operand of a binary operation node.
		 * @param AstInterface $item The binary operation node to modify
		 * @param AstInterface $right The new right operand
		 * @return void
		 * @throws \InvalidArgumentException If the item doesn't support binary operations
		 * @throws \BadMethodCallException If the node doesn't actually implement setRight()
		 */
		public static function setBinaryRight(AstInterface $item, AstInterface $right): void {
			// Validate that this is a supported binary operation node type
			if (!self::isBinaryOperationNode($item)) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			// Call setRight() - this may fail at runtime if the concrete class
			// doesn't implement this method, despite passing the type check above
			$item->setRight($right);
		}
	}