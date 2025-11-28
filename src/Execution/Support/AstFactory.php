<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Factory class for creating Abstract Syntax Tree (AST) nodes.
	 */
	class AstFactory {
		
		/**
		 * Creates a binary OR operator AST node.
		 * @param AstInterface $left The left operand of the OR operation
		 * @param AstInterface $right The right operand of the OR operation
		 * @return AstInterface An AST node representing the OR operation
		 */
		public static function createBinaryOrOperator(AstInterface $left, AstInterface $right): AstInterface {
			return new AstBinaryOperator($left, $right, 'OR');
		}
		
		/**
		 * Creates a binary AND operator AST node.
		 * @param AstInterface $left The left operand of the AND operation
		 * @param AstInterface $right The right operand of the AND operation
		 * @return AstInterface An AST node representing the AND operation
		 */
		public static function createBinaryAndOperator(AstInterface $left, AstInterface $right): AstInterface {
			return new AstBinaryOperator($left, $right, 'AND');
		}
		
		/**
		 * Creates a number literal AST node.
		 * @param int $value The integer value to be represented in the AST
		 * @return AstInterface An AST node representing the number literal
		 */
		public static function createNumber(int $value): AstInterface {
			return new AstNumber((string)$value);
		}
	}