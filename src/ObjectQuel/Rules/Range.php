<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;

	/**
	 * Class Range
	 *
	 * This class is responsible for parsing the RANGE clause in ObjectQuel queries.
	 * A RANGE clause defines the data sources and their aliases used in a query.
	 * Example: RANGE OF x IS Entity or RANGE OF y IS JSON_SOURCE("path/to/file.json")
	 *
	 * There is no `table` keyword: `RANGE OF x IS Name` is an entity range when
	 * `Name` resolves against the EntityStore, and a plain-table range (looked
	 * up against the live schema instead) when it doesn't. A table can't be
	 * targeted if it's shadowed by an entity of the exact same name — that's
	 * by design, not a gap.
	 */
	class Range {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * Used to decide whether `RANGE OF x IS Name` names an entity or a
		 * plain table — see this class's docblock.
		 */
		private EntityStore $entityStore;

		/**
		 * Range parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 * @param EntityStore $entityStore Used to distinguish entity ranges from plain-table ranges
		 */
		public function __construct(Lexer $lexer, EntityStore $entityStore) {
			$this->lexer = $lexer;
			$this->entityStore = $entityStore;
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
				return $this->parseSubqueryRange($alias->getStringValue());
			}
			
			// Check if the next token is 'JSON' to determine the type of data source
			if ($this->lexer->optionalMatch(Token::JsonSource)) {
				return $this->parseJsonRange($alias->getStringValue());
			}

			// Otherwise, it's a bare name — an entity range if it resolves against
			// the EntityStore, a plain-table range (resolved against the live
			// schema instead) if it doesn't. No keyword decides this; the lookup
			// does (see this class's docblock).
			return $this->parseEntityOrTableRange($alias);
		}

		/**
		 * Parse ranges
		 * @return AstRange[]
		 * @throws LexerException|ParserException
		 */
		protected function parseRanges(): array {
			$ranges = [];

			$rangeRule = new Range($this->lexer, $this->entityStore);

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
		private function parseSubqueryRange(string $alias): AstRangeDatabaseSubquery {
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
		 * Parse `RANGE OF alias IS Name[\SubName] [VIA ...]`, dispatching on
		 * whether `Name` resolves against the EntityStore: an entity range if
		 * so, a plain-table range if not. This is the one place that decision
		 * gets made — see this class's docblock.
		 * @param Token $alias The token containing the alias identifier
		 * @return AstRangeDatabase|AstRangeTable
		 * @throws LexerException|ParserException
		 */
		private function parseEntityOrTableRange(Token $alias): AstRangeDatabase|AstRangeTable {
			// Match and consume an 'Identifier' token for the entity/table name
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			// Handle namespaced names (Entity\SubEntity\SubSubEntity) — only ever
			// meaningful for an entity; a plain table name never legitimately
			// contains one
			while ($this->lexer->optionalMatch(Token::Backslash)) {
				$name .= "\\" . $this->lexer->match(Token::Identifier)->getStringValue();
			}

			if ($this->entityStore->exists($name)) {
				return $this->parseEntityRangeTail($alias->getStringValue(), $name);
			}

			return $this->parseTableRangeTail($alias->getStringValue(), $name);
		}

		/**
		 * Parse the remainder of an entity range once `Name` has already been
		 * resolved as an entity: an optional `via <relation>` naming a declared
		 * relation (`@OneToOne`/`@ManyToOne`/`@InverseOf`), resolved into a join
		 * condition later by RewriteViaRelationToJoinCondition — or, when what
		 * follows doesn't stop at a bare property chain, `via <condition>`
		 * naming a literal join condition directly, the same ad hoc form a
		 * plain-table range's `via` already supports. Both share one grammar
		 * slot: parsing the full expression grammar (not just a property
		 * chain) and then inspecting the result's shape distinguishes them —
		 * a bare identifier chain with nothing else parsed is indistinguishable
		 * from what parsePropertyChain() alone used to produce, so the relation
		 * form is unaffected; anything beyond that (an operator followed) is
		 * only ever meaningful as a literal condition. RewriteViaRelationToJoinCondition
		 * only ever rewrites a bare identifier chain (see its processNodeSide()
		 * guard) and leaves anything else untouched, so a literal condition
		 * passes through unchanged, unrewritten, exactly like a plain-table
		 * range's.
		 * @param string $alias The range alias
		 * @param string $entityName The resolved entity name
		 * @return AstRangeDatabase
		 * @throws LexerException|ParserException
		 */
		private function parseEntityRangeTail(string $alias, string $entityName): AstRangeDatabase {
			// Parse an optional 'VIA' statement (for filtering)
			$viaIdentifier = null;

			if ($this->lexer->lookahead() == Token::Via) {
				$this->lexer->match(Token::Via);

				$logicalExpressionRule = new LogicalExpression($this->lexer);
				$viaIdentifier = $logicalExpressionRule->parse();
			}

			// Match an optional semicolon at the end of the statement
			if ($this->lexer->lookahead() == Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}

			// Create and return the AST node for a database entity with alias, entity name, and optional VIA condition
			return new AstRangeDatabase($alias, $entityName, $viaIdentifier);
		}

		/**
		 * Parse the remainder of a plain-table range once `Name` has already
		 * been resolved as *not* an entity. Unlike an entity range's
		 * `via <relation>` (a relation name resolved against entity metadata
		 * later), a plain-table range has no relation catalog to name anything
		 * from — its `via` takes the literal join condition directly, parsed as
		 * a full expression up front (see AstRangeTable's docblock). Always a
		 * LEFT JOIN; there's no relation annotation to consult for "required",
		 * and this deliberately doesn't grow QUEL a way to spell INNER for it
		 * (see objectquel-plain-table-range-plan.md).
		 * @param string $alias The range alias
		 * @param string $tableName The physical table name
		 * @return AstRangeTable AST node representing a plain-table source
		 * @throws LexerException|ParserException
		 */
		private function parseTableRangeTail(string $alias, string $tableName): AstRangeTable {
			// Parse an optional 'VIA' clause — a literal join condition, not a
			// relation name (see this method's docblock)
			$joinCondition = null;

			if ($this->lexer->optionalMatch(Token::Via)) {
				$conditionRule = new LogicalExpression($this->lexer);
				$joinCondition = $conditionRule->parse();
			}

			// Match an optional semicolon at the end of the statement
			if ($this->lexer->lookahead() == Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}

			// Create and return the AST node for a plain-table range
			return new AstRangeTable($alias, $tableName, $joinCondition);
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
		 * @throws LexerException|ParserException
		 */
		private function parseJsonRange(string $alias): AstRangeJsonSource {
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
				$expression = $this->lexer->match(Token::String)->getStringValue();
			}
			
			// Consume the closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return the AST node for a JSON source with the alias, path, and optional JSONPath
			return new AstRangeJsonSource($alias, $path->getStringValue(), $expression);
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
		 * @throws LexerException|ParserException
		 */
		private function parseJsonNamedArguments(string $alias): AstRangeJsonSource {
			$file = null;
			$jsonPath = null;
			
			do {
				// Consume the argument name, the '=' separator, and the string value
				$name = $this->lexer->match(Token::Identifier)->getStringValue();
				$this->lexer->match(Token::Equals);
				$value = $this->lexer->match(Token::String)->getStringValue();
				
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