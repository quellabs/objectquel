<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class AstFactory {
		
		public static function createBinaryOrOperator(AstInterface $left, AstInterface $right): AstInterface {
			return new AstBinaryOperator($left, $right, 'OR');
		}

		public static function createBinaryAndOperator(AstInterface $left, AstInterface $right): AstInterface {
			return new AstBinaryOperator($left, $right, 'AND');
		}

		public static function createNumber(int $value): AstInterface {
			return new AstNumber($value);
		}
	}