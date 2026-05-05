<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AliasPlugAliasPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectJoinConditionIdentifiers;
	
	/**
	 * Main ObjectQuel query processor that handles parsing and validation
	 * This class orchestrates the complete query processing pipeline
	 */
	class ObjectQuel {
		private EntityStore $entityStore;
		private QueryTransformer $queryTransformer;
		private SemanticAnalyzer $queryValidator;
		
		/**
		 * Constructor to inject the EntityManager dependencies.
		 * @param EntityManager $entityManager The entity manager providing store and connection
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->queryTransformer = new QueryTransformer($this->entityStore);
			$this->queryValidator = new SemanticAnalyzer($this->entityStore);
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
				
				// Processing phase - Transform and enhance the AST
				$this->queryTransformer->transform($ast);
				
				// Validation phase - Ensure AST integrity and correctness
				$this->queryValidator->validate($ast);
				
				// The AST is now fully validated
				return $ast;

			} catch (ParserException|LexerException $e) {
				throw new QuelException("Syntax error: " . $e->getMessage(), 'syntax_error', 0, $e);
			} catch (SemanticException $e) {
				throw new QuelException($e->getMessage(), 'semantic_error', 0, $e);
			} catch (TransformationException $e) {
				throw new QuelException($e->getMessage(), 'transformation_error', 0, $e);
			} catch (\Throwable $e) {
				throw new QuelException("Query execution failed.", 'internal_error', 0, $e);
			}
		}
	}