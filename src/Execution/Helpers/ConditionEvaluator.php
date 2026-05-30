<?php
	
	namespace Quellabs\ObjectQuel\Execution\Helpers;
	
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchInMemory;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
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
		 * @noinspection PhpDuplicateMatchArmBodyInspection
		 */
		public static function evaluate(AstInterface $ast, array $contents, array $row, array $initialParams = []): mixed {
			// Determine the type of AST node and process accordingly
			switch (get_class($ast)) {
				// Handle literal value nodes - simply return their stored value
				case AstNumber::class:
					// AstNumber stores its value as a string; coerce to int or float
					// based on the node's own return-type declaration so that callers
					// receive a properly typed PHP value rather than a raw string.
					return $ast->getReturnType() === 'float'
						? (float) $ast->getValue()
						: (int) $ast->getValue();
				
				case AstString::class:  // String literal (e.g., "hello")
				case AstBool::class:    // Boolean literal (true/false)
					return $ast->getValue();
				
				// Handle arithmetic +/- nodes
				case AstTerm::class:
					$left  = self::toNumber(self::evaluate($ast->getLeft(),  $contents, $row, $initialParams));
					$right = self::toNumber(self::evaluate($ast->getRight(), $contents, $row, $initialParams));
					
					return match ($ast->getOperator()) {
						'+' => $left + $right,
						'-' => $left - $right,
						default => throw new QuelException("Unknown operator {$ast->getOperator()}"),
					};
				
				// Handle arithmetic */÷ nodes
				case AstFactor::class:
					$left  = self::toNumber(self::evaluate($ast->getLeft(),  $contents, $row, $initialParams));
					$right = self::toNumber(self::evaluate($ast->getRight(), $contents, $row, $initialParams));
					
					return match ($ast->getOperator()) {
						'*' => $left * $right,
						'/' => $left / $right,
						default => throw new QuelException("Unknown operator {$ast->getOperator()}"),
					};
				
				// Handle identifier node - fetch corresponding value from data row
				// (Identifiers represent column/field names in the data)
				case AstIdentifier::class:
					return $row[$ast->getCompleteName()] ?? null;
				
				// Handle cast expression: evaluate the inner expression, then coerce the
				// result to the requested PHP type. This is the in-memory equivalent of
				// SQL CAST() for JSON source ranges, which are never sent to a database.
				case AstCast::class:
					$castValue = self::evaluate($ast->getExpression(), $contents, $row, $initialParams);

					if (!is_scalar($castValue)) {
						return $castValue;
					}

					return match ($ast->getCastType()) {
						'int'     => intval($castValue),
						'float'   => floatval($castValue),
						'string'  => strval($castValue),
						'bool'    => (bool) $castValue,
						'decimal' => floatval($castValue),
						
						// 'datetime' is intentionally absent — it is a PHP-only cast handled
						// exclusively by the hydrator for database results. It cannot appear in
						// a purely in-memory context, so the default passthrough covers it.
						default   => $castValue,
					};
				
				// Handle NOT — negate the boolean result of the inner expression
				case AstNot::class:
					return !self::evaluate($ast->getExpression(), $contents, $row, $initialParams);
				
				// Handle parameter node - fetch value from parameters array
				// (Parameters are external values passed into the evaluation)
				case AstParameter::class:
					return $initialParams[$ast->getName()];
				
				// Handle comparison expressions (e.g., a = b, x > y)
				case AstExpression::class:
					// Evaluate both sides uniformly — AstRegExp evaluates to a RegExpValue,
					// which evaluateEquals() detects to apply the right matching strategy.
					$operator = $ast->getOperator();
					$left = self::evaluate($ast->getLeft(), $contents, $row, $initialParams);
					$right = self::evaluate($ast->getRight(), $contents, $row, $initialParams);
					
					// Apply the appropriate comparison operator
					return match ($operator) {
						'=' => self::evaluateEquals($left, $right),
						'<>', '!=' => !self::evaluateEquals($left, $right),
						'<' => $left < $right,
						'>' => $left > $right,
						'<=' => $left <= $right,
						'>=' => $left >= $right,
						default => throw new QuelException("Unknown operator {$operator}"),
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
						$parameters[] = self::stringify(self::evaluate($parameter, $contents, $row, $initialParams));
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
						$values = array_values(array_unique($values, SORT_REGULAR));
					}
					
					return array_sum($values) / count($values);
				
				// Handle sum() - returns the total of all non-null values.
				// AstSumU (SUM UNIQUE) sums only distinct values, matching SQL SUM(DISTINCT ...).
				// Mirrors the SQL layer's COALESCE behavior: empty/all-NULL set returns 0, not NULL.
				case AstSum::class:
				case AstSumU::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if ($ast instanceof AstSumU) {
						$values = array_values(array_unique($values, SORT_REGULAR));
					}
					
					return array_sum($values);
				
				// Handle count() - returns the number of non-null values.
				// AstCountU (COUNT UNIQUE) counts only distinct values, matching SQL COUNT(DISTINCT ...).
				// Empty/all-NULL set returns 0, consistent with SQL COUNT semantics.
				case AstCount::class:
				case AstCountU::class:
					$values = self::collectAggregateValues($ast, $contents, $initialParams);
					
					if ($ast instanceof AstCountU) {
						$values = array_values(array_unique($values, SORT_REGULAR));
					}
					
					return count($values);
				
				// Regular expression literal — returns a typed RegExpValue carrier so the
				// parent AstExpression handler can detect it after both sides are evaluated.
				case AstRegExp::class:
					return new RegExpValue($ast->getValue(), $ast->getFlags());
				
				// Exists() can only be used inside a database range
				case AstExists::class:
					return new QuelException("exists() is not allowed on non-database ranges");
				
				// Handle search() on non-database ranges (JSON sources, temp tables, cross-joins).
				case AstSearchInMemory::class:
					return self::evaluateSearch($ast, $row, $initialParams);
				
				// IS NULL — true when the evaluated expression is null
				case AstCheckNull::class:
					$value = self::evaluate($ast->getExpression(), $contents, $row, $initialParams);
					return $value === null;
				
				// IS NOT NULL — true when the evaluated expression is not null
				case AstCheckNotNull::class:
					$value = self::evaluate($ast->getExpression(), $contents, $row, $initialParams);
					return $value !== null;
				
				// IN — checks whether the left side matches any value in the list.
				case AstIn::class:
					$needle = self::evaluate($ast->getIdentifier(), $contents, $row, $initialParams);
						
					foreach ($ast->getParameters() as $parameter) {
						$candidate = self::evaluate($parameter, $contents, $row, $initialParams);
						
						if ($needle == $candidate) {
							return true;
						}
					}
					
					return false;
				
				// is_empty() — true when the value is null, empty string, or numeric zero
				case AstIsEmpty::class:
					$value = self::evaluate($ast->getValue(), $contents, $row, $initialParams);
					return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === false;
				
				// ANY(identifier where conditions) — returns 1 if at least one row
				// satisfies the conditions and has a non-null identifier value, 0 otherwise.
				// The identifier is the subject of the existential quantification: a row
				// only qualifies if both the conditions pass and the identifier is non-null.
				// Correlated references to the outer row (e.g. p.id = order.productId)
				// resolve naturally because $row is passed through to each inner evaluate().
				case AstAny::class:
					$anyConditions = $ast->getConditions();
					
					foreach ($contents as $innerRow) {
						// Merge the inner row with the outer row so correlated references
						// to the outer range (e.g. order.productId) are also resolvable.
						// Inner row keys take precedence to avoid range name collisions.
						$correlatedRow = array_merge($row, $innerRow);
						
						// Skip rows that do not satisfy the conditions
						if ($anyConditions !== null && !self::evaluate($anyConditions, $contents, $correlatedRow, $initialParams)) {
							continue;
						}
						
						// The identifier is the subject of the existential quantification —
						// a null value means no subject exists for this row, so skip it.
						if (self::evaluate($ast->getIdentifier(), $contents, $correlatedRow, $initialParams) === null) {
							continue;
						}
						
						return 1;
					}
					
					return 0;
				
				default:
					throw new QuelException("Unhandled AST node " . get_class($ast));
			}
		}
		
		/**
		 * Evaluates equality between two values, handling regexp and wildcard patterns.
		 *
		 * Three matching strategies, applied in order:
		 *   1. RegExpValue (produced by evaluating AstRegExp): apply preg_match() with
		 *      the stored PCRE pattern and flags.
		 *   2. String containing * or ?: apply fnmatch() case-insensitively to mirror
		 *      SQL LIKE wildcard behaviour (* = any sequence, ? = single character).
		 *   3. Anything else: loose equality (==), matching SQL's implicit type coercion.
		 *
		 * Called by the AstExpression handler for both = and <> operators;
		 * the caller negates the result for <>.
		 *
		 * @param mixed $left The evaluated left-hand side value.
		 * @param mixed $right The evaluated right-hand side value (may be a RegExpValue).
		 * @return bool
		 */
		private static function evaluateEquals(mixed $left, mixed $right): bool {
			// Regexp: right side evaluated to a RegExpValue carrier
			if ($right instanceof RegExpValue) {
				return (bool)preg_match($right->toPcre(), self::stringify($left));
			}
			
			// Wildcard: right side is a string containing * or ?
			// fnmatch() with FNM_CASEFOLD mirrors SQL LIKE case-insensitive behaviour
			if (is_string($right) && (str_contains($right, '*') || str_contains($right, '?'))) {
				return fnmatch($right, self::stringify($left), FNM_CASEFOLD);
			}
			
			// Plain equality — loose comparison to match SQL's implicit type coercion
			return $left == $right;
		}
		
		/**
		 * Evaluates an AstSearchInMemory node against a single data row.
		 *
		 * Mirrors the LIKE-chain strategy used in SQL:
		 *   - not_terms: row is excluded if any term appears in any field
		 *   - and_terms: every term must appear in at least one field
		 *   - or_terms:  at least one term must appear in at least one field
		 *                (if or_terms is empty the row passes — and/not already decided it)
		 *
		 * Comparison is case-insensitive via mb_strtolower.
		 *
		 * @param AstSearchInMemory $ast The search node to evaluate
		 * @param array<string, mixed> $row The data row to evaluate against
		 * @param array<string, mixed> $initialParams Runtime query parameters
		 * @return bool
		 * @throws QuelException
		 */
		private static function evaluateSearch(AstSearchInMemory $ast, array $row, array $initialParams): bool {
			// Parse the search string into or/and/not term buckets.
			// For literal search strings this was already done at planning time and is
			// returned as-is; for parameter-based strings it is parsed now using the
			// runtime value.
			$parsed = $ast->parseSearchData($initialParams);
			
			// Collect the field values for all searched columns in this row, lowercased
			// upfront so every subsequent comparison is case-insensitive without needing
			// to call mb_strtolower inside the inner loops.
			// NULL fields are skipped — a missing value cannot match any term.
			$fieldValues = [];
			
			foreach ($ast->getIdentifiers() as $identifier) {
				// Fetch the value
				$value = $row[$identifier->getCompleteName()] ?? null;
				
				// If found, add it to the list
				if ($value !== null) {
					$fieldValues[] = mb_strtolower(self::stringify($value));
				}
			}
			
			// not_terms: the row is excluded if any excluded term appears anywhere in
			// any of the searched fields. Checked first because exclusion is absolute —
			// a matching not_term overrules any and_term or or_term hits.
			foreach ($parsed['not_terms'] as $term) {
				$needle = mb_strtolower($term);
				
				foreach ($fieldValues as $fieldValue) {
					if (str_contains($fieldValue, $needle)) {
						return false;
					}
				}
			}
			
			// and_terms: every required term must appear in at least one searched field.
			// A single miss is enough to exclude the row, so we short-circuit as soon
			// as any term goes unmatched.
			foreach ($parsed['and_terms'] as $term) {
				$needle = mb_strtolower($term);
				$found = false;
				
				foreach ($fieldValues as $fieldValue) {
					if (str_contains($fieldValue, $needle)) {
						$found = true;
						break;
					}
				}
				
				if (!$found) {
					return false;
				}
			}
			
			// or_terms: at least one term must appear in at least one searched field.
			// We short-circuit on the first hit. If there are no or_terms the row
			// passes automatically — the and/not checks above were sufficient.
			if (!empty($parsed['or_terms'])) {
				foreach ($parsed['or_terms'] as $term) {
					$needle = mb_strtolower($term);
					
					foreach ($fieldValues as $fieldValue) {
						if (str_contains($fieldValue, $needle)) {
							return true;
						}
					}
				}
				
				// Went through every or_term against every field with no match.
				return false;
			}
			
			return true;
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
		 * @param list<array<string, mixed>> $contents The full row dataset to scan
		 * @param array<string, mixed> $initialParams Query parameters for condition evaluation
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
		
		/**
		 * Coerces a mixed evaluation result to int|float for arithmetic operations.
		 * Integers are returned as-is to preserve PHP's native int arithmetic.
		 * Numeric strings and floats are cast to float.
		 * Non-numeric values (null, bool, object) are treated as zero.
		 * @param mixed $value
		 * @return int|float
		 */
		private static function toNumber(mixed $value): int|float {
			if (is_int($value)) {
				return $value;
			}
			
			if (is_numeric($value)) {
				return (float)$value;
			}
			
			return 0;
		}
		
		/**
		 * Converts a mixed value to string for comparison or concatenation.
		 * Null is coerced to empty string; all other scalars are cast normally.
		 * Non-scalar types (arrays, objects without __toString) are not expected
		 * here and will trigger a native PHP error, which is the correct behaviour.
		 * @param mixed $value
		 * @return string
		 */
		private static function stringify(mixed $value): string {
			// Null values are allowed
			if ($value === null) {
				return '';
			}
			
			// Otherwise it needs to be a scalar value (int, float, bool, string)
			if (!is_scalar($value)) {
				throw new \InvalidArgumentException('stringify() expects a scalar or null value');
			}
			
			return (string)$value;
		}
	}