<?php
	
	namespace Quellabs\ObjectQuel\QueryManagement;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Responsible for evaluating conditions in AST nodes against data rows
	 *
	 * This class provides functionality to evaluate various types of AST nodes
	 * against data rows, enabling condition checking in both query execution
	 * and result joining operations.
	 */
	class ConditionEvaluator {
		
		/**
		 * Evaluates a condition AST against a data row
		 *
		 * This function traverses an Abstract Syntax Tree (AST) representing a condition
		 * and evaluates it against provided data. It handles various node types including
		 * literals, identifiers, parameters, expressions, and binary operations.
		 *
		 * @param AstInterface $ast The AST condition to evaluate
		 * @param array $row The data row to evaluate against (key-value pairs)
		 * @param array $initialParams Optional parameters that can be referenced in the AST
		 * @return mixed The result of the evaluation (could be boolean, string, number, etc.)
		 * @throws QuelException When an unknown AST node or operator is encountered
		 */
		public function evaluate(AstInterface $ast, array $row, array $initialParams=[]): mixed {
			// Determine the type of AST node and process accordingly
			switch(get_class($ast)) {
				// Handle literal value nodes - simply return their stored value
				case AstNumber::class:  // Numeric literal (e.g., 42, 3.14)
				case AstString::class:  // String literal (e.g., "hello")
				case AstBool::class:    // Boolean literal (true/false)
					return $ast->getValue();
				
				// Handle identifier node - fetch corresponding value from data row
				// (Identifiers represent column/field names in the data)
				case AstIdentifier::class:
					return $row[$ast->getCompleteName()];
				
				// Handle parameter node - fetch value from parameters array
				// (Parameters are external values passed into the evaluation)
				case AstParameter::class:
					return $initialParams[$ast->getName()];
				
				// Handle comparison expressions (e.g., a = b, x > y)
				case AstExpression::class:
					// Recursively evaluate both sides of the expression
					$left = $this->evaluate($ast->getLeft(), $row, $initialParams);
					$right = $this->evaluate($ast->getRight(), $row, $initialParams);
					
					// Apply the appropriate comparison operator
					return match ($ast->getOperator()) {
						'=' => $left == $right,         // Equality check (loose comparison)
						'<>', '!=' => $left != $right,  // Not equal (supports both syntaxes)
						'<' => $left < $right,          // Less than
						'>' => $left > $right,          // Greater than
						'<=' => $left <= $right,        // Less than or equal to
						'>=' => $left >= $right,        // Greater than or equal to
						default => throw new QuelException("Unknown operator {$ast->getOperator()}"),
					};
				
				// Handle logical operators (AND, OR) for boolean conditions
				case AstBinaryOperator::class:
					// Recursively evaluate both sides of the binary operation
					$left = $this->evaluate($ast->getLeft(), $row, $initialParams);
					$right = $this->evaluate($ast->getRight(), $row, $initialParams);
					
					// Apply the appropriate logical operator
					return match ($ast->getOperator()) {
						'AND' => $left && $right,    // Logical AND - both sides must be true
						'OR' => $left || $right,     // Logical OR - at least one side must be true
						default => throw new QuelException("Unknown operator {$ast->getOperator()}"),
					};
				
				// Handle case where we encounter an unknown/unsupported AST node type
				default:
					throw new QuelException("Unknown AST node " . get_class($ast));
			}
		}
	}