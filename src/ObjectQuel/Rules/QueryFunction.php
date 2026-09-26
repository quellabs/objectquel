<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDenseRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstLag;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstLead;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNtile;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRank;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRowNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * QueryFunction class handles parsing of function calls in ObjectQuel queries.
	 */
	class QueryFunction {
		
		/** @var Lexer The lexer instance used for tokenizing input */
		private Lexer $lexer;
		
		/** @var ArithmeticExpression The expression rule parser for handling nested expressions */
		private ArithmeticExpression $expressionRule;
		
		/**
		 * QueryFunction constructor
		 * @param ArithmeticExpression $expression The expression parser instance
		 */
		public function __construct(ArithmeticExpression $expression) {
			$this->expressionRule = $expression;
			$this->lexer = $expression->getLexer();
		}
		
		/**
		 * Main parsing method - dispatches to appropriate function parser
		 *
		 * This method acts as a router, determining which specific function parser
		 * to call based on the function name. It uses PHP 8's match expression
		 * for clean, efficient dispatching.
		 *
		 * Supported functions:
		 * - Aggregate: count, countu, avg, avgu
		 * - String: concat, search
		 * - Type checking: is_empty, is_numeric, is_integer, is_float, is_null
		 * - Temporal: date
		 * - Utility: exists
		 *
		 * @param string $command The function name to parse (case-insensitive)
		 * @return AstInterface The appropriate AST node for the parsed function
		 * @throws LexerException When token matching fails
		 * @throws ParserException When function name is not recognized or parsing fails
		 * @throws \ReflectionException When reflection fails
		 */
		public function parse(string $command): AstInterface {
			return match (strtolower($command)) {
				'count' => $this->parseCount(),
				'countu' => $this->parseCountU(),
				'avg' => $this->parseAvg(),
				'avgu' => $this->parseAvgU(),
				'max' => $this->parseMax(),
				'min' => $this->parseMin(),
				'sum' => $this->parseSum(),
				'sumu' => $this->parseSumU(),
				'any' => $this->parseAny(),
				'rank' => $this->parseRank(),
				'dense_rank' => $this->parseDenseRank(),
				'row_number' => $this->parseRowNumber(),
				'ntile' => $this->parseNtile(),
				'lag' => $this->parseLag(),
				'lead' => $this->parseLead(),
				'concat' => $this->parseConcat(),
				'search_score' => $this->parseSearchScore(),
				'is_empty' => $this->parseIsEmpty(),
				'is_null' => $this->parseIsNull(),
				'is_numeric' => $this->parseIsNumeric(),
				'is_integer' => $this->parseIsInteger(),
				'is_float' => $this->parseIsFloat(),
				'ifnull' => $this->parseIfNull(),
				'exists' => $this->parseExists(),
				'date' => $this->parseDate(),
				default => throw new ParserException("Command {$command} is not valid."),
			};
		}
		
		/**
		 * Generic parser for simple single-parameter functions
		 * @template T of AstInterface
		 * @param class-string<T> $astClass The fully qualified AST class name to instantiate
		 * @return T The instantiated AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseSingleParameter(string $astClass): AstInterface {
			// Match opening parenthesis
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse the parameter - either as property chain (entity.field) or general expression
			$parameter = $this->parseArgument();
			
			// Match closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return the appropriate AST node
			return new $astClass($parameter);
		}
		
		/**
		 * Parses a value argument as a full expression, so a comparison or AND/OR needs no extra parentheses.
		 * @return AstInterface The argument node
		 * @throws LexerException|ParserException
		 */
		private function parseArgument(): AstInterface {
			return (new LogicalExpression($this->lexer))->parse();
		}
		
		/**
		 * Generic parser for aggregates
		 * @template T of AstInterface
		 * @param class-string<T> $astClass The fully qualified AST class name to instantiate
		 * @param bool $allowWindowClauses Whether an inline `by` and/or `sort by` may be used.
		 *        `sort by` (with or without `by` alongside it) flips this call into a windowed
		 *        running aggregate. A bare `by` alone does NOT window it — it's an explicit
		 *        GROUP BY override instead, resolved later by AggregateOptimizer. Left false
		 *        for ANY() and the DISTINCT variants (COUNTU/AVGU/SUMU), which can use neither.
		 * @return T The instantiated AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseAggregateFunction(string $astClass, bool $allowWindowClauses = false): AstInterface {
			// Match opening parenthesis
			$this->lexer->match(Token::ParenthesesOpen);

			// Parse the parameter - either as property chain (entity.field) or general expression
			$parameter = $this->expressionRule->parse();

			// Optional inline `by`, explicit PARTITION BY columns
			$partitionBy = $allowWindowClauses ? $this->optionalBy() : null;

			// Optional WHERE statement
			$conditions = null;
			if ($this->lexer->optionalMatch(TOKEN::Where)) {
				$logicalExpression = new LogicalExpression($this->lexer);
				$conditions = $logicalExpression->parse();
			}

			// Optional inline `sort by`, flipping SQL generation to a window function
			$order = $allowWindowClauses ? $this->optionalSortBy() : null;

			// Match closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);

			// Create and return the appropriate AST node
			return new $astClass($parameter, $conditions, $order, $partitionBy);
		}

		/**
		 * Parses `(expr sort by ...)` for a value-bearing sequence function (ntile, lag,
		 * lead) that has no WHERE clause and requires the sort-by list — these functions
		 * have no meaning without an explicit row order.
		 * @template T of AstInterface
		 * @param class-string<T> $astClass The fully qualified AST class name to instantiate
		 * @param string $functionName Lowercase function name, used in the error message
		 * @return T The instantiated AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseValueSequenceFunction(string $astClass, string $functionName): AstInterface {
			$this->lexer->match(Token::ParenthesesOpen);
			$parameter = $this->expressionRule->parse();
			$partitionBy = $this->optionalBy();
			$order = $this->requiredSortBy($functionName);
			$this->lexer->match(Token::ParenthesesClose);

			return new $astClass($parameter, $order, $partitionBy);
		}

		/**
		 * Parses `(sort by ...)` for a no-argument sequence function (rank, dense_rank,
		 * row_number) — these have no value argument and no meaning without an explicit
		 * row order.
		 * @template T of AstInterface
		 * @param class-string<T> $astClass The fully qualified AST class name to instantiate
		 * @param string $functionName Lowercase function name, used in the error message
		 * @return T The instantiated AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseNoArgumentSequenceFunction(string $astClass, string $functionName): AstInterface {
			$this->lexer->match(Token::ParenthesesOpen);
			$partitionBy = $this->optionalBy();
			$order = $this->requiredSortBy($functionName);
			$this->lexer->match(Token::ParenthesesClose);

			return new $astClass($order, $partitionBy);
		}

		/**
		 * Parses the optional inline `by expr, ...` list found inside aggregate and
		 * sequence function calls — explicit PARTITION BY columns, overriding the
		 * optimizer's default inference from the other SELECT items. No `asc`/`desc`,
		 * unlike `sort by` — partitioning has no order. Returns null when no `by` is present.
		 * @return array<int, AstInterface>|null
		 * @throws LexerException|ParserException
		 */
		private function optionalBy(): ?array {
			if (!$this->lexer->optionalMatch(Token::By)) {
				return null;
			}

			$expressions = [];

			do {
				$expressions[] = $this->expressionRule->parse();
			} while ($this->lexer->optionalMatch(Token::Comma));

			return $expressions;
		}

		/**
		 * Parses the optional inline `sort by expr [asc|desc], ...` list found inside
		 * aggregate and sequence function calls. Returns null when no `sort by` is present.
		 * @return array<int, array{ast: AstInterface, order: string}>|null
		 * @throws LexerException|ParserException
		 */
		private function optionalSortBy(): ?array {
			if (!$this->lexer->optionalMatch(Token::Sort)) {
				return null;
			}

			$this->lexer->match(Token::By);
			return (new SortExpressionParser($this->lexer, $this->expressionRule))->parse();
		}

		/**
		 * Same as optionalSortBy(), but throws when no `sort by` is present — for
		 * sequence functions that are meaningless without an explicit row order.
		 * @param string $functionName Lowercase function name, used in the error message
		 * @return array<int, array{ast: AstInterface, order: string}>
		 * @throws LexerException|ParserException
		 */
		private function requiredSortBy(string $functionName): array {
			$order = $this->optionalSortBy();

			if ($order === null) {
				throw new ParserException("{$functionName}() requires an inline 'sort by' clause; it has no meaning without an explicit row order.");
			}

			return $order;
		}
		
		/**
		 * Parse COUNT() function - counts rows/elements in a collection
		 * Returns the number of elements in the specified collection or entity.
		 * @return AstCount The COUNT AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseCount(): AstCount {
			return $this->parseAggregateFunction(AstCount::class, allowWindowClauses: true);
		}
		
		/**
		 * Parse COUNTU() function - counts unique elements in a collection
		 * Returns the number of unique elements in the specified collection.
		 * @return AstCountU The COUNTU AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseCountU(): AstCountU {
			return $this->parseAggregateFunction(AstCountU::class);
		}
		
		/**
		 * Parse AVG() function - calculates average of numeric values
		 * Returns the arithmetic mean of all non-null numeric values in the collection.
		 * @return AstAvg The AVG AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseAvg(): AstAvg {
			return $this->parseAggregateFunction(AstAvg::class, allowWindowClauses: true);
		}
		
		/**
		 * Parse AVGU() function - calculates average of unique numeric values
		 * Returns the arithmetic mean of unique non-null numeric values.
		 * @return AstAvgU The AVGU AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseAvgU(): AstAvgU {
			return $this->parseAggregateFunction(AstAvgU::class);
		}
		
		/**
		 * Parse MAX() function - finds the maximum value among numeric values
		 * Returns the largest non-null numeric value from the input.
		 * @return AstMax The AstMax AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseMax(): AstMax {
			return $this->parseAggregateFunction(AstMax::class, allowWindowClauses: true);
		}
		
		/**
		 * Parse MIN() function - finds the minimum value among numeric values
		 * Returns the smallest non-null numeric value from the input.
		 * @return AstMin The AstMin AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseMin(): AstMin {
			return $this->parseAggregateFunction(AstMin::class, allowWindowClauses: true);
		}
		
		/**
		 * Parse SUM() function - calculates the sum of numeric values
		 * Returns the total of all non-null numeric values from the input.
		 * @return AstSum The AstSum AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseSum(): AstSum {
			return $this->parseAggregateFunction(AstSum::class, allowWindowClauses: true);
		}
		
		/**
		 * Parse SUMU() function - calculates the sum of unique numeric values
		 * Returns the total of all non-null numeric values from the input.
		 * @return AstSumU The AstSum AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseSumU(): AstSumU {
			return $this->parseAggregateFunction(AstSumU::class);
		}
		
		/**
		 * Parses the ANY aggregate function from the ObjectQuel query.
		 * ANY is a specialized aggregate function that returns 1 if any matching records exist,
		 * or 0 if no records exist. Unlike COUNT, ANY is optimized to stop execution as soon
		 * as the first matching record is found, making it more efficient for existence checks.
		 * @return AstAny The parsed ANY function AST node containing the field reference
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseAny(): AstAny {
			return $this->parseAggregateFunction(AstAny::class);
		}

		/**
		 * Parse RANK() — sequential rank within the partition, with gaps after ties.
		 * No value argument; requires an inline `sort by`.
		 * @return AstRank
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseRank(): AstRank {
			return $this->parseNoArgumentSequenceFunction(AstRank::class, 'rank');
		}

		/**
		 * Parse DENSE_RANK() — sequential rank within the partition, no gaps after ties.
		 * No value argument; requires an inline `sort by`.
		 * @return AstDenseRank
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseDenseRank(): AstDenseRank {
			return $this->parseNoArgumentSequenceFunction(AstDenseRank::class, 'dense_rank');
		}

		/**
		 * Parse ROW_NUMBER() — unique sequential position within the partition; no ties.
		 * No value argument; requires an inline `sort by`.
		 * @return AstRowNumber
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseRowNumber(): AstRowNumber {
			return $this->parseNoArgumentSequenceFunction(AstRowNumber::class, 'row_number');
		}

		/**
		 * Parse NTILE(n) — distributes partition rows into n roughly-equal buckets.
		 * Requires an inline `sort by`.
		 * @return AstNtile
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseNtile(): AstNtile {
			return $this->parseValueSequenceFunction(AstNtile::class, 'ntile');
		}

		/**
		 * Parse LAG(expr) — value of expr from the previous row in the partition.
		 * Requires an inline `sort by`.
		 * @return AstLag
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseLag(): AstLag {
			return $this->parseValueSequenceFunction(AstLag::class, 'lag');
		}

		/**
		 * Parse LEAD(expr) — value of expr from the next row in the partition.
		 * Requires an inline `sort by`.
		 * @return AstLead
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseLead(): AstLead {
			return $this->parseValueSequenceFunction(AstLead::class, 'lead');
		}
		
		/**
		 * Parse is_empty() function - checks if value is falsy
		 * Returns true if the value is considered empty (null, empty string, or 0).
		 * This is useful for filtering out records with missing or empty data.
		 * @return AstIsEmpty The is_empty AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIsEmpty(): AstIsEmpty {
			return $this->parseSingleParameter(AstIsEmpty::class);
		}
		
		/**
		 * Parses is_null(expr) — a strict NULL check, unlike is_empty()
		 * which also treats 0, '', and false as empty.
		 * @return AstCheckNull
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIsNull(): AstCheckNull {
			return $this->parseSingleParameter(AstCheckNull::class);
		}
		
		/**
		 * Parse is_numeric() function - checks if value is numeric
		 * Returns true if the value is numeric (integer, float, or numeric string).
		 * Useful for data validation and type checking in queries.
		 * @return AstIsNumeric The is_numeric AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIsNumeric(): AstIsNumeric {
			return $this->parseSingleParameter(AstIsNumeric::class);
		}
		
		/**
		 * Parse is_integer() function - checks if value is an integer
		 * Returns true if the value is specifically an integer type.
		 * More restrictive than is_numeric() as it excludes floats and numeric strings.
		 * @return AstIsInteger The is_integer AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIsInteger(): AstIsInteger {
			return $this->parseSingleParameter(AstIsInteger::class);
		}
		
		/**
		 * Parse is_float() function - checks if value is a floating-point number
		 * Returns true if the value is specifically a float/double type.
		 * Complements is_integer() for precise numeric type checking.
		 * @return AstIsFloat The is_float AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIsFloat(): AstIsFloat {
			return $this->parseSingleParameter(AstIsFloat::class);
		}
		
		/**
		 * Parse ifnull() function. This functions as a simple COALESCE in SQL
		 * @return AstIfNull
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseIfNull(): AstIfNull {
			$this->lexer->match(Token::ParenthesesOpen);
			
			$expression = $this->expressionRule->parse();
			
			$this->lexer->match(Token::Comma);
			
			$altValue = $this->expressionRule->parse();
			
			$this->lexer->match(Token::ParenthesesClose);

			return new AstIfNull($expression, $altValue);
		}
		
		/**
		 * Parse exists() function - checks entity existence and affects joins
		 * @return AstExists The exists AST node
		 * @throws LexerException|ParserException
		 */
		protected function parseExists(): AstExists {
			// Parse entity reference
			$this->lexer->match(Token::ParenthesesOpen);
			$entity = $this->expressionRule->parsePropertyChain();
			$this->lexer->match(Token::ParenthesesClose);
			
			// Validate that entity is a simple reference without property chains
			if ($entity->hasNext()) {
				throw new ParserException("exists operator takes an entity as parameter.");
			}
			
			return new AstExists($entity);
		}
		
		/**
		 * Parse CONCAT() function - concatenates multiple expressions into a string
		 * Accepts variable number of parameters and concatenates them into a single string.
		 * Each parameter can be a string literal, field reference, or complex expression.
		 * @return AstConcat The CONCAT AST node containing all parameters
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseConcat(): AstConcat {
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse variable number of parameters separated by commas
			$parameters = [];
			
			do {
				$parameters[] = $this->expressionRule->parse();
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			$this->lexer->match(Token::ParenthesesClose);
			
			return new AstConcat($parameters);
		}
		
		/**
		 * Parse date() function — converts a datetime expression to a Unix timestamp
		 * so that temporal arithmetic is expressed as plain integer math.
		 *
		 * Accepted argument forms:
		 *   date("now")        — current time as Unix timestamp
		 *   date("6 days")     — interval folded to integer literal at parse time
		 *   date(o.orderDate)  — datetime column converted to Unix timestamp in SQL
		 *   date(:param)       — runtime parameter treated as datetime column
		 *
		 * Pure interval strings are pre-computed here so the SQL generator can
		 * emit a bare integer without any function call.
		 *
		 * @return AstDate The date AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseDate(): AstDate {
			$this->lexer->match(Token::ParenthesesOpen);
			$expression = $this->expressionRule->parse();
			$this->lexer->match(Token::ParenthesesClose);

			// Pre-compute seconds for plain interval string literals ("6 days", "2 hours", …).
			// "now" returns null and is left for the SQL generator to emit NOW().
			// Datetime strings ("2024-01-15", "2024-01-15 10:30:00") are passed through
			// unchanged so the SQL generator can wrap them in UNIX_TIMESTAMP().
			// Anything else that is not a recognised interval is a parse error.
			$foldedSeconds = null;

			if ($expression instanceof AstString) {
				$value = trim($expression->getValue());
				
				if (!$this->isDatetimeString($value) && strtolower($value) !== 'now') {
					$intervalParser = new IntervalParser();
					$foldedSeconds = $intervalParser->parse($value);
				}
			}

			return new AstDate($expression, $foldedSeconds);
		}
		
		/**
		 * Parse SEARCH_SCORE() function - returns the full-text relevance score for a set of fields.
		 * Uses the same syntax as SEARCH() but emits a MATCH...AGAINST value expression instead
		 * of a boolean condition. Intended for use in SELECT and ORDER BY clauses.
		 *
		 * Requires a @FullTextIndex annotation on the entity covering all searched columns.
		 * An exception is thrown at SQL generation time if no matching index is found.
		 *
		 * Example:
		 *   retrieve (p, search_score(p.name, p.description, :term) as score)
		 *   where search(p.name, p.description, :term)
		 *   sort by score desc
		 *
		 * @return AstSearchScore The SEARCH_SCORE AST node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseSearchScore(): AstSearchScore {
			$this->lexer->match(Token::ParenthesesOpen);
			
			$identifiers = $this->parseIdentifierList();
			
			if (empty($identifiers)) {
				throw new ParserException("Missing identifier list for SEARCH_SCORE operator.");
			}
			
			$searchString = $this->expressionRule->parse();
			
			if ((!$searchString instanceof AstString) && (!$searchString instanceof AstParameter)) {
				throw new ParserException(
					"SEARCH_SCORE() requires a string literal or :parameter as its last argument, got " . get_class($searchString) . ". " .
					"Did you mean to quote the search term or use a :parameter?"
				);
			}
			
			$this->lexer->match(Token::ParenthesesClose);
			
			return new AstSearchScore($identifiers, $searchString);
		}
		
		/**
		 * Helper method that parses a sequence of identifiers separated by commas,
		 * stopping before the final search string argument.
		 *
		 * Identifiers are field references (e.g. p.name, p.description). The list ends
		 * when the next token after a comma is not an identifier — that token is the
		 * search string. A trailing comma with no following identifier is an error.
		 *
		 * @return AstIdentifier[] Array of parsed identifier AST nodes
		 * @throws LexerException|ParserException
		 */
		private function parseIdentifierList(): array {
			$identifiers = [];
			
			do {
				// Not an identifier — stop here, the caller will parse the search term next
				$next = $this->lexer->lookahead();

				if ($next !== Token::Identifier) {
					// A closing paren after a comma is a genuine trailing comma error: search(p.content, )
					if (!empty($identifiers) && $next === Token::ParenthesesClose) {
						throw new ParserException("Unexpected token after comma in SEARCH() identifier list. Expected a field identifier or search string.");
					}
					
					break;
				}
				
				$identifier = $this->expressionRule->parsePropertyChain();
				$identifiers[] = $identifier;
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $identifiers;
		}
		
		/**
		 * Returns true when the string is a date or datetime literal that can be
		 * passed directly to UNIX_TIMESTAMP(). Accepted formats:
		 *   YYYY-MM-DD
		 *   YYYY-MM-DD HH:MM:SS
		 *   YYYY-MM-DD HH:MM:SS.ffffff   (fractional seconds)
		 * @param string $value
		 * @return bool
		 */
		private function isDatetimeString(string $value): bool {
			return (bool) preg_match(
				'/^\d{4}-\d{2}-\d{2}(?:\s\d{2}:\d{2}:\d{2}(?:\.\d+)?)?$/',
				$value
			);
		}
	}