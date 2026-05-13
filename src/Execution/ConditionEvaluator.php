<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
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
		 * @param list<array<string, mixed>> $contents The full row dataset (used by aggregate functions)
		 * @param array<string, mixed> $row The data row to evaluate against (key-value pairs)
		 * @param array<string, mixed> $initialParams Optional parameters that can be referenced in the AST
		 * @return mixed The result of the evaluation (could be boolean, string, number, etc.)
		 * @throws QuelException When an unknown AST node or operator is encountered
		 */
		public static function evaluate(AstInterface $ast, array $contents, array $row, array $initialParams = []): mixed {
			// Determine the type of AST node and process accordingly
			switch (get_class($ast)) {
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
					$left = self::evaluate($ast->getLeft(), $contents, $row, $initialParams);
					$right = self::evaluate($ast->getRight(), $contents, $row, $initialParams);
					
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
					$left = self::evaluate($ast->getLeft(), $contents, $row, $initialParams);
					$right = self::evaluate($ast->getRight(), $contents, $row, $initialParams);
					
					// Apply the appropriate logical operator
					return match ($ast->getOperator()) {
						'AND' => $left && $right,    // Logical AND - both sides must be true
						'OR' => $left || $right,     // Logical OR - at least one side must be true
						default => throw new QuelException("Unknown operator {$ast->getOperator()}"),
					};
				
				// Handle is_numeric
				case AstIsNumeric::class:
					$value = self::evaluate($ast->getValue(), $contents, $row, $initialParams);
					return is_numeric($value);
				
				// Handle is_integer - true if the value represents a whole number.
				// Uses is_numeric() first because row values are often strings (e.g. from JSON);
				// casting to int and back checks there is no fractional part.
				case AstIsInteger::class:
					$value = self::evaluate($ast->getValue(), $contents, $row, $initialParams);
					return is_numeric($value) && (int)$value == $value;
				
				// Handle is_float - true if the value is numeric but not a whole number.
				case AstIsFloat::class:
					$value = self::evaluate($ast->getValue(), $contents, $row, $initialParams);
					return is_numeric($value) && (int)$value != $value;
				
				// Handle concat()
				case AstConcat::class:
					$parameters = [];
					foreach ($ast->getParameters() as $parameter) {
						$parameters[] = (string)self::evaluate($parameter, $contents, $row, $initialParams);
					}
					
					return implode('', $parameters);
				
				// Handle ifnull()
				case AstIfNull::class:
					$left = self::evaluate($ast->getExpression(), $contents, $row, $initialParams);
					$right = self::evaluate($ast->getAltValue(), $contents, $row, $initialParams);
					return $left ?? $right;
				
				// Handle min() / max() - scan all rows and return the smallest or largest non-null value.
				// Mirrors SQL MIN()/MAX() semantics: NULLs excluded, empty/all-NULL set returns NULL.
				case AstMin::class:
				case AstMax::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if (empty($values)) {
						return null;
					}
					
					return $ast instanceof AstMin ? min($values) : max($values);
				
				// Handle avg() - returns the arithmetic mean of non-null values.
				// AstAvgU (AVG UNIQUE) averages only distinct values, matching SQL AVG(DISTINCT ...).
				// NULLs are excluded from both count and sum; empty/all-NULL set returns NULL.
				case AstAvg::class:
				case AstAvgU::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if (empty($values)) {
						return null;
					}
					
					if ($ast instanceof AstAvgU) {
						$values = array_values(array_unique($values));
					}
					
					return array_sum($values) / count($values);
				
				// Handle sum() - returns the total of all non-null values.
				// AstSumU (SUM UNIQUE) sums only distinct values, matching SQL SUM(DISTINCT ...).
				// Mirrors the SQL layer's COALESCE behavior: empty/all-NULL set returns 0, not NULL.
				case AstSum::class:
				case AstSumU::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if ($ast instanceof AstSumU) {
						$values = array_values(array_unique($values));
					}
					
					return array_sum($values);
				
				// Handle count() - returns the number of non-null values.
				// AstCountU (COUNT UNIQUE) counts only distinct values, matching SQL COUNT(DISTINCT ...).
				// Empty/all-NULL set returns 0, consistent with SQL COUNT semantics.
				case AstCount::class:
				case AstCountU::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if ($ast instanceof AstCountU) {
						$values = array_values(array_unique($values));
					}
					
					return count($values);
				
				// Exists() can only be used inside a database range
				case AstExists::class:
					return new QuelException("exists() is not allowed on non-database ranges");
				
				// Handle case where we encounter an unknown/unsupported AST node type
				default:
					throw new QuelException("Unhandled AST node " . get_class($ast));
			}
		}
		
		/**
		 * Collects non-null values from all rows that pass the aggregate's optional filter.
		 *
		 * Shared by MIN, MAX, AVG, SUM, and COUNT: each iterates the full dataset, skips
		 * rows that fail the conditions clause, evaluates the identifier expression, and
		 * excludes NULLs — matching SQL aggregate semantics. The caller then applies its
		 * own reduction (min, max, mean, sum, count) to the returned array.
		 *
		 * @param AstAggregate $ast The aggregate node supplying identifier and conditions
		 * @param list<array<string, mixed>> $contents        The full row dataset to scan
		 * @param array<string, mixed>       $initialParams   Query parameters for condition evaluation
		 * @return list<mixed> Non-null values collected from qualifying rows
		 * @throws QuelException
		 */
		private static function collectAggregateValues(AstAggregate $ast, array $contents, array $initialParams): array {
			$identifier = $ast->getIdentifier();
			$aggConditions = $ast->getConditions();
			$values = [];
			
			foreach ($contents as $contentRow) {
				// Skip rows that don't satisfy the aggregate's filter clause.
				// This is the in-memory equivalent of SQL's CASE WHEN ... END inside an aggregate,
				// e.g. sum(price where active = 1) only accumulates rows where active equals 1.
				if (
					$aggConditions !== null &&
					!self::evaluate($aggConditions, $contents, $contentRow, $initialParams)
				) {
					continue;
				}
				
				// Evaluate the expression being aggregated for this row (e.g. the field name).
				$value = self::evaluate($identifier, $contents, $contentRow, $initialParams);
				
				// SQL aggregate functions ignore NULLs in all modes (MIN, MAX, SUM, AVG, COUNT).
				// Excluding them here means callers get a clean array they can reduce directly
				// without any further special-casing.
				if ($value !== null) {
					$values[] = $value;
				}
			}
			
			return $values;
		}
	}