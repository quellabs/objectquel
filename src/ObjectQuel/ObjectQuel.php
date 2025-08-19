<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AliasPlugAliasPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GatherReferenceJoinValues;
	
	/**
	 * Main ObjectQuel query processor that handles parsing and validation
	 * This class orchestrates the complete query processing pipeline
	 */
	class ObjectQuel {
		private EntityStore $entityStore;
		private QueryTransformer $queryTransformer;
		private QueryValidator $queryValidator;
		
		/**
		 * Constructor to inject the EntityManager dependencies.
		 * @param EntityManager $entityManager The entity manager providing store and connection
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->queryTransformer = new QueryTransformer($this->entityStore);
			$this->queryValidator = new QueryValidator($this->entityStore);
		}
		
		/**
		 * Parses a Quel query and returns its validated AST representation.
		 * @param string $query The Quel query string to parse
		 * @return AstRetrieve|null The validated AST or null if parsing fails
		 * @throws QuelException If parsing, validation, or processing fails
		 */
		public function parse(string $query): ?AstRetrieve {
			try {
				// Convert the raw query string into an Abstract Syntax Tree
				$ast = $this->parseQueryToAst($query);
				
				// Processing phase - Transform and enhance the AST
				$this->queryTransformer->transform($ast);
				
				// Validation phase - Ensure AST integrity and correctness
				$this->queryValidator->validate($ast);
				
				// Final processing phase - Apply final transformations
				$this->processWithVisitor($ast, AliasPlugAliasPattern::class);
				$this->addReferencedValuesToQuery($ast);
				
				// The AST is now fully validated
				return $ast;
				
			} catch (ParserException $e) {
				// Handle parsing failures by wrapping in domain-specific exception
				// This provides consistent error handling while preserving original error context
				// ParserException indicates issues in the parsing phase specifically
				throw new QuelException("Query parsing failed: " . $e->getMessage(), 0, $e);
			}
		}
		
		// ========== PARSING METHODS ==========
		
		/**
		 * Parses the query string into an AST using lexer and parser.
		 * @param string $query The query string to parse
		 * @return AstRetrieve The parsed AST
		 * @throws QuelException If the AST is not a retrieve operation
		 */
		private function parseQueryToAst(string $query): AstRetrieve {
			try {
				// Create a lexer to break the query string into tokens (keywords, identifiers, operators, etc.)
				$lexer = new Lexer($query);
				
				// Create a parser that takes the tokenized input and builds an Abstract Syntax Tree
				$parser = new Parser($lexer);
				
				// Execute the parsing process to generate the AST representation of the query
				// This transforms the linear token sequence into a hierarchical tree structure
				$ast = $parser->parse();
				
				// Ensure the parsed AST represents a RETRIEVE operation
				// This method specifically handles RETRIEVE queries
				if (!$ast instanceof AstRetrieve) {
					throw new QuelException("Invalid query type: expected retrieve operation");
				}
				
				// Return the validated AST ready for further processing
				return $ast;
				
			} catch (LexerException | ParserException $e) {
				// Catch parsing errors and wrap them in a domain-specific exception
				// This provides a consistent error interface while preserving the original error details
				// The original exception is chained for debugging purposes
				throw new QuelException("Query parsing failed: " . $e->getMessage(), 0, $e);
			}
		}
		
		// ========== PROCESSING METHODS ==========
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param string $visitorClass The visitor class name
		 * @param mixed ...$args Arguments to pass to visitor constructor
		 * @return object The visitor instance after processing
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): object {
			$visitor = new $visitorClass(...$args);
			$ast->accept($visitor);
			return $visitor;
		}

		/**
		 * Adds referenced field values to the query's value list for join conditions.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addReferencedValuesToQuery(AstRetrieve $ast): void {
			// Early exit if there are no conditions to process
			// Without conditions, there won't be any referenced fields to gather
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Use a visitor pattern to traverse the AST and collect all identifiers
			// that are referenced in join conditions but not already in the SELECT list
			// GatherReferenceJoinValues is a specialized visitor that finds these missing references
			$visitor = $this->processWithVisitor($ast, GatherReferenceJoinValues::class);
			
			// Process each identifier that was found by the visitor
			foreach ($visitor->getIdentifiers() as $identifier) {
				// Create a deep copy of the identifier to avoid modifying the original
				// This ensures we don't accidentally affect other parts of the query tree
				$clonedIdentifier = $identifier->deepClone();
				
				// Wrap the cloned identifier in an alias using its complete name
				// This creates a proper SELECT field that can be referenced in joins
				$alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				
				// Mark this field as invisible in the final result set
				// These are technical fields needed for joins, not user-requested data
				// This prevents them from appearing in the output while still being available for JOIN conditions
				$alias->setVisibleInResult(false);
				
				// Add the aliased field to the query's value list (SELECT clause)
				// This ensures the field is available for join processing even though it's not visible to users
				$ast->addValue($alias);
			}
		}
	}