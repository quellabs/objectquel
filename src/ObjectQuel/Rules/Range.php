<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	// Import necessary AST classes and exceptions
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
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
		 * Parse a JSON source definition in a RANGE clause
		 * Format: RANGE OF alias IS JSON_SOURCE("path/to/file.json"[, "optional filter expression"])
		 * @param Token $alias The token containing the alias identifier
		 * @return AstRangeJsonSource AST node representing a JSON data source
		 * @throws LexerException If token matching fails
		 */
		private function parseJsonSource(Token $alias): AstRangeJsonSource {
			// Match opening parenthesis after JSON_SOURCE
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Get the file path string
			$path = $this->lexer->match(Token::String);
			
			// Check for an optional filter expression (separated by comma)
			$expression = null;

			if ($this->lexer->optionalMatch(Token::Comma)) {
				$expression = $this->lexer->match(Token::String);
				$expression = $expression->getValue();
			}
			
			// Match closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return the AST node for a JSON source with the alias, path, and optional filter
			return new AstRangeJsonSource($alias->getValue(), $path->getValue(), $expression);
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
			
			// Check if the next token is 'JSON_SOURCE' to determine the type of data source
			if ($this->lexer->optionalMatch(Token::JsonSource)) {
				// Handle JSON source definition
				return $this->parseJsonSource($alias);
			}
			
			// Otherwise, treat it as a database entity source
			return $this->parseEntity($alias);
		}
	}