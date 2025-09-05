<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class SubqueryFactory {
		
		public static function createWindowFunction(AstInterface $expression, string $returnType): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$expression,
				[],
				null,
				$returnType
			);
		}
		
		public static function createCorrelatedScalar(
			AstInterface  $expression,
			array         $ranges,
			?AstInterface $whereConditions,
			string        $returnType
		): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_SCALAR,
				$expression,
				$ranges,
				$whereConditions,
				$returnType
			);
		}
	}