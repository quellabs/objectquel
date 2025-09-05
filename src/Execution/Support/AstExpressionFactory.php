<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Factory class for creating AstSubquery instances with predefined configurations.
	 *
	 * This factory provides static methods to create different types of subqueries
	 * commonly used in ObjectQuel AST construction, ensuring consistent parameter
	 * passing and reducing boilerplate code.
	 */
	class AstExpressionFactory {
		
		/**
		 * Creates a window function subquery.
		 *
		 * Window functions perform calculations across a set of table rows that are
		 * somehow related to the current row, without requiring a GROUP BY clause.
		 *
		 * @param AstInterface $expression The window function expression (e.g., ROW_NUMBER(), SUM() OVER())
		 * @param string|null $origin Optional origin identifier for debugging/tracing
		 * @return AstSubquery             Window function subquery with empty ranges and no WHERE conditions
		 */
		public static function createWindowFunction(
			AstInterface $expression,
			?string      $origin = null
		): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$expression,
				[],             // Window functions don't use ranges in the same way as correlated subqueries
				null,  // Window functions don't have WHERE conditions at the subquery level
				$origin
			);
		}
		
		/**
		 * Creates a correlated scalar subquery.
		 *
		 * A correlated scalar subquery returns a single value and references columns
		 * from the outer query. The correlation is established through the ranges
		 * and WHERE conditions that reference outer query columns.
		 *
		 * @param AstInterface $expression The scalar expression to evaluate (often an aggregate)
		 * @param array $ranges Array of table/range references that establish correlation
		 * @param AstInterface|null $whereConditions Optional WHERE clause conditions for filtering
		 * @param string|null $origin Optional origin identifier for debugging/tracing
		 * @return AstSubquery                       Correlated scalar subquery that returns a single value
		 */
		public static function createCorrelatedScalar(
			AstInterface  $expression,
			array         $ranges,
			?AstInterface $whereConditions,
			?string       $origin = null
		): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_SCALAR,
				$expression,
				$ranges,
				$whereConditions,
				$origin
			);
		}
		
		/**
		 * Creates an EXISTS subquery.
		 *
		 * EXISTS subqueries return true if the subquery returns any rows, false otherwise.
		 * They're commonly used for filtering based on the existence of related data
		 * and are often more efficient than IN clauses for large datasets.
		 *
		 * @param AstInterface $expression The expression to check for existence (often just a column or constant)
		 * @param array $ranges Array of table/range references for the subquery FROM clause
		 * @param AstInterface|null $whereConditions WHERE clause conditions that typically correlate with outer query
		 * @param string|null $origin Optional origin identifier for debugging/tracing
		 * @return AstSubquery                       EXISTS subquery that returns boolean existence result
		 */
		public static function createExists(
			AstInterface  $expression,
			array         $ranges,
			?AstInterface $whereConditions,
			?string       $origin = null
		): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_EXISTS,
				$expression,
				$ranges,
				$whereConditions,
				$origin
			);
		}
		
		/**
		 * Creates a CASE WHEN subquery expression.
		 *
		 * This creates a CASE WHEN conditional expression that can handle ANY(...) operations
		 * in SELECT contexts. In ObjectQuel, ANY() operations in value contexts (like SELECT clauses)
		 * are transformed into CASE WHEN expressions that return appropriate values based on
		 * the existence of matching rows.
		 *
		 * The expression typically evaluates to:
		 * CASE WHEN EXISTS(SELECT ... FROM ranges WHERE conditions) THEN 1 ELSE 0 END
		 *
		 * @param array $ranges Array of table/range references for the subquery FROM clause
		 * @param AstInterface|null $whereConditions WHERE clause conditions that determine the CASE condition
		 * @param string|null $origin Optional origin identifier for debugging/tracing
		 * @return AstSubquery                       CASE WHEN subquery expression
		 */
		public static function createCaseWhen(
			array         $ranges,
			?AstInterface $whereConditions,
			?string       $origin = null
		): AstSubquery {
			return new AstSubquery(
				AstSubquery::TYPE_CASE_WHEN,
				null,                  // CASE WHEN expressions don't have a direct aggregation expression
				$ranges,
				$whereConditions,
				$origin
			);
		}
	}