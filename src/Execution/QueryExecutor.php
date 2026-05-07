<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\HydrationException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Execution\Executors\DatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonQueryExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\ExecutionPlanBuilder;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;
	use Quellabs\ObjectQuel\Planner\QueryTransformer;
	
	/**
	 * Orchestrates query execution by delegating to specialized executors.
	 *
	 * TempTableStages are NOT routed through executeStage() — they are handled
	 * exclusively by PlanExecutor before result-producing stages run. executeStage()
	 * only ever receives ExecutionStage instances (database or JSON ranges).
	 */
	class QueryExecutor {
		
		private EntityManager $entityManager;
		private PlatformCapabilities $capabilities;
		private DatabaseAdapter $connection;
		private PlanExecutor $planExecutor;
		private QueryTransformer $transformer;
		private \Quellabs\ObjectQuel\ObjectQuel\QueryTransformer $queryTransformer;
		private SemanticAnalyzer $semanticAnalyser;
		private DatabaseQueryExecutor $databaseExecutor;
		private JsonQueryExecutor $jsonExecutor;
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager
		 * @param DatabaseQueryExecutor|null $databaseExecutor
		 */
		public function __construct(
			EntityManager          $entityManager,
			?DatabaseQueryExecutor $databaseExecutor = null
		) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->capabilities = new PlatformCapabilities($this->connection);
			$this->transformer = new QueryTransformer($entityManager, $this->capabilities);
			$this->queryTransformer = new \Quellabs\ObjectQuel\ObjectQuel\QueryTransformer($entityManager->getEntityStore());
			$this->semanticAnalyser = new SemanticAnalyzer($entityManager->getEntityStore());
			
			// Create specialized executors
			$conditionEvaluator = new ConditionEvaluator();
			$this->planExecutor = new PlanExecutor($this, $conditionEvaluator);
			$this->databaseExecutor = $databaseExecutor ?? new DatabaseQueryExecutor($entityManager, $this->capabilities);
			$this->jsonExecutor = new JsonQueryExecutor($conditionEvaluator);
		}
		
		/**
		 * Returns the entity manager object
		 * @return EntityManager
		 */
		public function getEntityManager(): EntityManager {
			return $this->entityManager;
		}
		
		/**
		 * Returns the DatabaseAdapter
		 * @return DatabaseAdapter
		 */
		public function getConnection(): DatabaseAdapter {
			return $this->connection;
		}
		
		/**
		 * Execute a database query and return the results
		 * @param ExecutionStageInterface $stage
		 * @param array<int|string, mixed> $initialParams (Optional) An array of parameters to bind to the query
		 * @return list<array<string, mixed>>
		 * @throws QuelException|EntityResolutionException
		 */
		public function executeStage(ExecutionStageInterface $stage, array $initialParams = []): array {
			$queryType = $stage->getRange() instanceof AstRangeJsonSource ? 'json' : 'database';
			
			return match ($queryType) {
				'json' => $this->jsonExecutor->execute($stage, $this->normalizeParams($initialParams)),
				'database' => $this->databaseExecutor->execute($stage, $this->normalizeParams($initialParams)),
			};
		}
		
		/**
		 * Execute a decomposed query plan
		 * @param string $query The query to execute
		 * @param array<int|string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): QuelResult {
			try {
				// Clear SQL list
				$this->databaseExecutor->resetLastExecutedSql();
				
				// Parse the input query string into an Abstract Syntax Tree (AST)
				$ast = $this->parse($query);
				
				// Processing phase #1 - Transform and enhance the AST
				$this->queryTransformer->transform($ast);
				
				// Validation phase - Ensure AST integrity and correctness
				$this->semanticAnalyser->validate($ast);
				
				// Processing phase #2 - Transform and enhance the AST
				$this->transformer->transform($ast);
				
				// Decompose the query
				$planner = new ExecutionPlanBuilder();
				$executionPlan = $planner->build($ast, $this->normalizeParams($parameters));
				
				// Execute the returned execution plan and return the QuelResult
				$result = $this->planExecutor->execute($executionPlan);
				
				// Hydrate and return the query result.
				return new QuelResult($this->entityManager, $ast, $result);
			} catch (ParserException|LexerException $e) {
				throw new QuelException("Syntax error: " . $e->getMessage(), 'syntax_error', 0, $e);
			} catch (SemanticException $e) {
				throw new QuelException($e->getMessage(), 'semantic_error', 0, $e);
			} catch (TransformationException $e) {
				throw new QuelException($e->getMessage(), 'transformation_error', 0, $e);
			} catch (HydrationException $e) {
				throw new QuelException($e->getMessage(), 'hydration_error', 0, $e);
			} catch (EntityResolutionException $e) {
				throw new QuelException($e->getMessage(), 'resolution_error', 0, $e);
			} catch (\Throwable $e) {
				throw new QuelException("Query execution failed.", 'internal_error', 0, $e);
			}
		}
		
		/**
		 * Return the executed SQL
		 * @return list<string>
		 */
		public function getLastExecutedSql(): array {
			return $this->databaseExecutor->getLastExecutedSql();
		}
		
		/**
		 * Parses a Quel query and returns its validated AST representation.
		 * @param string $query The Quel query string to parse
		 * @return AstRetrieve The validated AST or null if parsing fails
		 * @throws LexerException
		 * @throws ParserException
		 * @throws QuelException If parsing, validation, or processing fails
		 */
		private function parse(string $query): AstRetrieve {
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
			
			// The AST is now fully validated
			return $ast;
		}
		
		/**
		 * Normalizes an array of parameters by casting all keys to strings.
		 * @param array<int|string, mixed> $params The parameters to normalize.
		 * @return array<string, mixed> The normalized parameters with string keys.
		 */
		private function normalizeParams(array $params): array {
			$normalized = [];
			
			foreach ($params as $key => $value) {
				$normalized[(string)$key] = $value;
			}
			
			return $normalized;
		}
	}