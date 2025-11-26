<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * Parser for 'retrieve' statements in the ObjectQuel language.
	 */
	class Retrieve {
		
		/** @var int Default number of records per page when window size is not specified */
		private const int DEFAULT_WINDOW_SIZE = 1;
		
		/** @var string Default sort order when ASC/DESC is not explicitly specified */
		private const string DEFAULT_SORT_ORDER = '';
		
		private ?string $temporaryTableName = null;
		
		/** @var Lexer The lexer instance that tokenizes input and provides tokens for parsing */
		private Lexer $lexer;
		
		/** @var ArithmeticExpression Parser rule for handling arithmetic expressions in field lists and sort clauses */
		private ArithmeticExpression $expressionRule;
		
		/** @var FilterExpression Parser rule for handling filter expressions in WHERE clauses */
		private FilterExpression $filterExpressionRule;
		
		/**
		 * Initialize the Retrieve parser with required dependencies.
		 * @param Lexer $lexer The lexer instance for token processing
		 */
		public function __construct(Lexer $lexer, ?string $temporaryTableName = null) {
			$this->lexer = $lexer;
			$this->temporaryTableName = $temporaryTableName;
			$this->expressionRule = new ArithmeticExpression($this->lexer);
			$this->filterExpressionRule = new FilterExpression($this->lexer);
		}
		
		/**
		 * Parse a complete 'retrieve' statement from the ObjectQuel language.
		 * @param array $directives Query modification directives affecting behavior
		 * @param AstRangeDatabase[] $ranges Database ranges to query from
		 * @return AstRetrieve Complete AST representation of the retrieve operation
		 * @throws LexerException|ParserException on parsing or lexical errors
		 */
		public function parse(array $directives, array $ranges): AstRetrieve {
			$this->lexer->match(Token::Retrieve);
			
			$retrieve = new AstRetrieve(
				$directives,
				$ranges,
				$this->lexer->optionalMatch(Token::Unique)
			);
			
			$this->parseFieldList($retrieve);
			$this->parseWhereClause($retrieve);
			$this->parseSortClause($retrieve);
			$this->parseWindowClause($retrieve);
			$this->consumeOptionalSemicolon();
			
			return $retrieve;
		}
		
		/**
		 * Parse all field expressions in the retrieve statement's field list.
		 * @param AstRetrieve $retrieve The AST node to populate with field data
		 * @return AstAlias[] Array of parsed field aliases
		 * @throws LexerException|ParserException
		 */
		private function parseValues(AstRetrieve $retrieve): array {
			$values = [];
			
			do {
				$values[] = $this->parseFieldExpression($retrieve);
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $values;
		}
		
		/**
		 * Parse a single field expression with its optional alias definition.
		 * @param AstRetrieve $retrieve The AST node for macro management
		 * @return AstAlias The parsed field alias containing name and expression
		 * @throws LexerException|ParserException
		 */
		private function parseFieldExpression(AstRetrieve $retrieve): AstAlias {
			// Store the current lexer position for potential alias name generation
			$startPos = $this->lexer->getPos();
			
			// Check for explicit alias syntax: "alias = expression"
			// If found, capture the alias identifier token, otherwise set to null
			$aliasToken = $this->isExplicitAlias() ? $this->lexer->match(Token::Identifier) : null;
			
			// If we found an explicit alias, consume the required equals sign
			if ($aliasToken) {
				$this->lexer->match(Token::Equals);
			}
			
			// Parse the actual expression (right side of alias or standalone expression)
			$expression = $this->expressionRule->parse();
			
			// Validate that the parsed expression is suitable for use in a field context
			$this->validateFieldExpression($expression);
			
			// Determine the final alias name (either from explicit token or auto-generated)
			$aliasName = $this->determineAliasName($aliasToken, $startPos);
			
			// Handle any macro processing related to this alias definition
			$this->processAliasMacro($retrieve, $aliasToken, $expression);
			
			// Create and return the AST node representing this field alias
			// Trim whitespace from alias name to ensure clean identifiers
			return new AstAlias(trim($aliasName), $expression);
		}
		
		/**
		 * Check if the current parsing position indicates an explicit alias definition.
		 * @return bool True if next token is equals sign, indicating explicit alias
		 */
		private function isExplicitAlias(): bool {
			return $this->lexer->peekNext() === Token::Equals;
		}
		
		/**
		 * Validate that the parsed expression is allowed in field lists.
		 * @param mixed $expression The parsed expression to validate
		 * @throws ParserException if expression type is not allowed in field lists
		 */
		private function validateFieldExpression(mixed $expression): void {
			if ($expression instanceof AstRegExp) {
				throw new ParserException(
					'Regular expressions are not allowed in the value list. Please remove the regular expression.'
				);
			}
		}
		
		/**
		 * Determine the appropriate alias name for a field expression.
		 *
		 * For explicit aliases (e.g., "custom_id = x.id"), returns the explicit name unchanged.
		 * For auto-generated aliases (e.g., "x.id"), generates from source and may rewrite
		 * for temporary table context.
		 *
		 * @param Token|null $aliasToken The explicit alias token if present, null for auto-generated
		 * @param int $startPos Starting position for source slice calculation
		 * @return string The resolved alias name
		 */
		private function determineAliasName(?Token $aliasToken, int $startPos): string {
			// Explicit aliases are used as-is and never rewritten
			// Example: "custom_id = x.id" -> always "custom_id"
			if ($aliasToken) {
				return $aliasToken->getValue();
			}
			
			// Auto-generated aliases are derived from source text
			// Example: "x.id" -> captured as "x.id" from source
			$sourceSlice = $this->lexer->getSourceSlice($startPos, $this->lexer->getPos() - $startPos);
			
			// When inside a temporary table, rewrite auto-generated aliases
			// to use the external temporary table name instead of internal range name
			// Example: "x.id" -> "c.id" when temporary table is named "c"
			if ($this->temporaryTableName !== null) {
				$sourceSlice = $this->rewriteAliasForTemporaryTable($sourceSlice);
			}
			
			return $sourceSlice;
		}
		
		/**
		 * Process alias macros and validate for duplicates within the retrieve statement.
		 * @param AstRetrieve $retrieve The retrieve AST node managing macros
		 * @param Token|null $aliasToken The alias token if an explicit alias was provided
		 * @param mixed $expression The expression associated with this alias
		 * @throws ParserException if duplicate alias name is detected
		 */
		private function processAliasMacro(AstRetrieve $retrieve, ?Token $aliasToken, $expression): void {
			if (!$aliasToken) {
				return;
			}
			
			$aliasName = $aliasToken->getValue();
			
			if ($retrieve->macroExists($aliasName)) {
				throw new ParserException(
					"Duplicate variable name detected: '{$aliasName}'. Please use unique names."
				);
			}
			
			$retrieve->addMacro($aliasName, $expression);
		}
		
		/**
		 * Parse the field list section of the retrieve statement.
		 * @param AstRetrieve $retrieve The retrieve AST node to populate with fields
		 * @throws LexerException|ParserException on parsing errors
		 */
		private function parseFieldList(AstRetrieve $retrieve): void {
			$this->lexer->match(Token::ParenthesesOpen);
			
			foreach ($this->parseValues($retrieve) as $value) {
				$retrieve->addValue($value);
			}
			
			$this->lexer->match(Token::ParenthesesClose);
		}
		
		/**
		 * Parse the optional WHERE clause for result filtering.
		 * @param AstRetrieve $retrieve The retrieve AST node to set conditions on
		 * @throws LexerException|ParserException on parsing errors in filter expression
		 */
		private function parseWhereClause(AstRetrieve $retrieve): void {
			if ($this->lexer->optionalMatch(Token::Where)) {
				$retrieve->setConditions($this->filterExpressionRule->parse());
			}
		}
		
		/**
		 * Parse the optional SORT BY clause for result ordering.
		 * @param AstRetrieve $retrieve The retrieve AST node to set sort specifications on
		 * @throws LexerException|ParserException on parsing errors in sort expressions
		 */
		private function parseSortClause(AstRetrieve $retrieve): void {
			if (!$this->lexer->optionalMatch(Token::Sort)) {
				return;
			}
			
			$this->lexer->match(Token::By);
			$sortArray = $this->parseSortExpressions();
			$retrieve->setSort($sortArray);
		}
		
		/**
		 * Parse individual sort expressions and their order specifications.
		 * @return array[] Array of sort specifications with 'ast' and 'order' keys
		 * @throws LexerException|ParserException on expression parsing errors
		 */
		private function parseSortExpressions(): array {
			$sortArray = [];
			
			do {
				$expression = $this->expressionRule->parse();
				$order = $this->parseSortOrder();
				
				$sortArray[] = [
					'ast'   => $expression,
					'order' => $order
				];
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $sortArray;
		}
		
		/**
		 * Parse the sort order specification (ASC/DESC) for a sort expression.
		 * @return string Sort order: 'asc', 'desc', or default empty string
		 * @throws LexerException
		 */
		private function parseSortOrder(): string {
			if ($this->lexer->optionalMatch(Token::Asc)) {
				return 'asc';
			}
			
			if ($this->lexer->optionalMatch(Token::Desc)) {
				return 'desc';
			}
			
			return self::DEFAULT_SORT_ORDER;
		}
		
		/**
		 * Parse the optional WINDOW clause for pagination support.
		 * @param AstRetrieve $retrieve The retrieve AST node to set pagination on
		 * @throws LexerException on parsing errors in window specification
		 */
		private function parseWindowClause(AstRetrieve $retrieve): void {
			// Check if WINDOW keyword is present - if not, skip window parsing entirely
			if (!$this->lexer->optionalMatch(Token::Window)) {
				return;
			}
			
			// Parse the required window number (which window/page to retrieve)
			// This represents the page number or window index in the result set
			$windowNumber = $this->lexer->match(Token::Number);
			
			// Parse the window size specification (how many records per window)
			$windowSize = $this->parseWindowSize();
			
			// Apply the parsed pagination settings to the retrieve AST node
			// Set which window/page number to retrieve (0-based)
			$retrieve->setWindow($windowNumber->getValue());
			
			// Set how many records should be included in each window/page
			$retrieve->setWindowSize($windowSize);
		}
		
		/**
		 * Parse the window size specification from a WINDOW clause.
		 * @return int The parsed window size or default if not specified
		 * @throws LexerException on parsing errors
		 */
		private function parseWindowSize(): int {
			// Short notation: WINDOW 1, 10
			if ($this->lexer->optionalMatch(Token::Comma)) {
				$sizeToken = $this->lexer->match(Token::Number);
				return $sizeToken->getValue();
			}
			
			// Explicit notation: WINDOW 1 USING WINDOW_SIZE 10
			if ($this->lexer->optionalMatch(Token::Using)) {
				$this->lexer->match(Token::WindowSize);
				$sizeToken = $this->lexer->match(Token::Number);
				return $sizeToken->getValue();
			}
			
			return self::DEFAULT_WINDOW_SIZE;
		}
		
		/**
		 * Consume an optional trailing semicolon from the retrieve statement.
		 * @throws LexerException if semicolon token cannot be properly consumed
		 */
		private function consumeOptionalSemicolon(): void {
			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
		}
		
		private function rewriteAliasForTemporaryTable(string $alias): string {
			if (!str_contains($alias, '.')) {
				return $alias;
			}
			
			$parts = explode('.', $alias, 2);
			return $this->temporaryTableName . '.' . $parts[1];
		}
	}