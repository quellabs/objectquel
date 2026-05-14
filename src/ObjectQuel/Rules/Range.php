<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * Class Range
	 *
	 * This class is responsible for parsing the RANGE clause in ObjectQuel queries.
	 * A RANGE clause defines the data sources and their aliases used in a query.
	 * Example: RANGE OF x IS Entity or RANGE OF y IS JSON_SOURCE("path/to/file.json")
	 */
	class Range {
		
		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;
		
		/**
		 * Range parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}
		
		
		/**
		 * Parse a complete 'RANGE' clause in the ObjectQuel query.
		 *
		 * A 'RANGE' clause defines an alias for a data source, which can be either:
		 * 1. A database entity: RANGE OF x IS Entity[\SubEntity] [VIA condition]
		 * 2. A JSON file: RANGE OF x IS JSON_SOURCE("path/to/file.json"[, "expression"])
		 * @return AstRange AST node representing the RANGE clause
		 * @throws LexerException|ParserException If parsing fails
		 */
		public function parse(): AstRange {
			// Match and consume the 'RANGE' keyword
			$this->lexer->match(Token::Range);
			
			// Match and consume the 'OF' keyword
			$this->lexer->match(Token::Of);
			
			// Match and consume an 'Identifier' token for the alias
			$alias = $this->lexer->match(Token::Identifier);
			
			// Match and consume the 'IS' keyword
			$this->lexer->match(Token::Is);
			
			// Check if the next token is an opening parenthesis; if so it's a subquery specification
			if ($this->lexer->lookahead() == Token::ParenthesesOpen) {
				return $this->parseQuery($alias->getValue());
			}
			
			// Check if the next token is 'JSON' to determine the type of data source
			if ($this->lexer->optionalMatch(Token::JsonSource)) {
				return $this->parseJson($alias->getValue());
			}
			
			// Otherwise, treat it as a database entity source
			return $this->parseEntity($alias);
		}
		
		/**
		 * Parse ranges
		 * @return AstRange[]
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseRanges(): array {
			$ranges = [];
			
			$rangeRule = new Range($this->lexer);
			
			while ($this->lexer->peek()->getType() == Token::Range) {
				$ranges[] = $rangeRule->parse();
			}
			
			return $ranges;
		}
		
		/**
		 * Parses a database query expression wrapped in parentheses.
		 * @param string $alias The alias to assign to the resulting range
		 * @return AstRangeDatabaseSubquery The parsed database range with query attached
		 * @throws LexerException If lexer encounters invalid tokens
		 * @throws ParserException If syntax structure is invalid
		 */
		private function parseQuery(string $alias): AstRangeDatabaseSubquery {
			// Match opening parenthesis - start of query expression
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse range definitions that will be available to the query
			$ranges = $this->parseRanges();
			
			// Parse the actual retrieve query using the defined ranges
			$query = new Retrieve($this->lexer, true);
			$retrieve = $query->parse([], $ranges);
			
			// Match closing parenthesis - end of query expression
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create the database range with a temporary name
			return new AstRangeDatabaseSubquery($alias, $retrieve);
		}
		
		/**
		 * Parse an entity (database) definition in a RANGE clause
		 * Format: RANGE OF alias IS Entity[\SubEntity] [VIA condition]
		 * @param Token $alias The token containing the alias identifier
		 * @return AstRangeDatabase AST node representing a database entity source
		 * @throws LexerException|ParserException If parsing fails
		 */
		private function parseEntity(Token $alias): AstRangeDatabase {
			// Match and consume an 'Identifier' token for the entity name
			$entityName = $this->lexer->match(Token::Identifier)->getValue();
			
			// Handle namespaced entity names (Entity\SubEntity\SubSubEntity)
			while ($this->lexer->optionalMatch(Token::Backslash)) {
				$entityName .= "\\" . $this->lexer->match(Token::Identifier)->getValue();
			}
			
			// Parse an optional 'VIA' statement (for filtering)
			$viaIdentifier = null;
			
			if ($this->lexer->lookahead() == Token::Via) {
				$this->lexer->match(Token::Via);
				
				// Use the LogicalExpression rule to parse the condition after VIA
				$logicalExpressionRule = new LogicalExpression($this->lexer);
				$viaIdentifier = $logicalExpressionRule->parse();
			}
			
			// Match an optional semicolon at the end of the statement
			if ($this->lexer->lookahead() == Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
			
			// Create and return the AST node for a database entity with alias, entity name, and optional VIA condition
			return new AstRangeDatabase($alias->getValue(), $entityName, $viaIdentifier);
		}
		
		/**
		 * Parse a JSON source definition in a RANGE clause.
		 *
		 * Supports two forms:
		 *
		 * Positional (original):
		 *   json_source('path/to/file.json')
		 *   json_source('path/to/file.json', '$.rows')
		 *
		 * Named arguments (extended):
		 *   json_source(file='path/to/file.json', jsonPath='$.rows')
		 *   json_source(jsonPath='$.rows', file='path/to/file.json')
		 *
		 * Named form allows arguments in any order and makes each argument optional
		 * at parse time (missing required arguments are caught at execution time).
		 *
		 * @param string $alias The alias
		 * @return AstRangeJsonSource AST node representing a JSON data source
		 * @throws LexerException If token matching fails
		 * @throws ParserException If a named argument is unrecognised or duplicated
		 */
		private function parseJson(string $alias): AstRangeJsonSource {
			// Consume the opening parenthesis
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Detect named-argument form: an identifier immediately followed by '='
			// distinguishes json_source(file='...') from json_source('...')
			if ($this->lexer->peek()->getType() === Token::Identifier && $this->lexer->peekNext() === Token::Equals) {
				return $this->parseJsonNamedArguments($alias);
			}
			
			// Positional form
			// Get the file path string
			$path = $this->lexer->match(Token::String);
			
			// Check for an optional JSONPath expression (separated by comma)
			$expression = null;
			
			if ($this->lexer->optionalMatch(Token::Comma)) {
				$expression = $this->lexer->match(Token::String)->getValue();
			}
			
			// Consume the closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return the AST node for a JSON source with the alias, path, and optional JSONPath
			return new AstRangeJsonSource($alias, $path->getValue(), $expression);
		}
		
		/**
		 * Parse the named-argument form of json_source().
		 * Called after the opening parenthesis has been consumed and the lookahead
		 * confirms the named form (identifier followed by '=').
		 *
		 * Recognised argument names: file, jsonPath
		 *
		 * @param string $alias The range alias
		 * @return AstRangeJsonSource
		 * @throws LexerException
		 * @throws ParserException
		 */
		private function parseJsonNamedArguments(string $alias): AstRangeJsonSource {
			$file = null;
			$jsonPath = null;
			
			do {
				// Consume the argument name, the '=' separator, and the string value
				$name = $this->lexer->match(Token::Identifier)->getValue();
				$this->lexer->match(Token::Equals);
				$value = $this->lexer->match(Token::String)->getValue();
				
				switch ($name) {
					case 'file':
						// Guard against the same argument appearing twice
						if ($file !== null) {
							throw new ParserException("Duplicate argument 'file' in json_source()");
						}
						
						$file = $value;
						break;
					
					case 'jsonPath':
						// Guard against the same argument appearing twice
						if ($jsonPath !== null) {
							throw new ParserException("Duplicate argument 'jsonPath' in json_source()");
						}
						
						$jsonPath = $value;
						break;
					
					default:
						throw new ParserException("Unknown argument '{$name}' in json_source(); expected 'file' or 'jsonPath'");
				}
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			// Consume the closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// 'file' is the only required argument; 'jsonPath' is optional
			if ($file === null) {
				throw new ParserException("Missing required argument 'file' in json_source()");
			}
			
			// Create and return the AST node for a JSON source with the alias, path, and optional JSONPath
			return new AstRangeJsonSource($alias, $file, $jsonPath);
		}
	}