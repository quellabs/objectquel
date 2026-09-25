<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Classifies nodes by the SQL they render to: a predicate, or a scalar value that may hold a boolean.
	 * Nodes in neither class (e.g. is_empty(), search()) render the same in both positions.
	 */
	class BooleanExpressionClassifier {

		/**
		 * Determines whether a node renders as a SQL predicate.
		 * @param AstInterface $node Node to classify
		 * @return bool True when the node renders to a SQL predicate (comparison, AND/OR, NOT, IN, IS [NOT] NULL)
		 */
		public static function isPredicate(AstInterface $node): bool {
			return $node instanceof AstExpression
				|| $node instanceof AstBinaryOperator
				|| $node instanceof AstNot
				|| $node instanceof AstIn
				|| $node instanceof AstCheckNull
				|| $node instanceof AstCheckNotNull;
		}

		/**
		 * Determines whether a node renders as a scalar SQL value.
		 * @param AstInterface $node Node to classify
		 * @return bool True when the node renders to a scalar value, which isn't a predicate without boolean literals
		 */
		public static function isScalarValue(AstInterface $node): bool {
			return $node instanceof AstIdentifier
				|| $node instanceof AstBool
				|| $node instanceof AstParameter
				|| $node instanceof AstRoutineCall
				|| $node instanceof AstCast;
		}
	}
