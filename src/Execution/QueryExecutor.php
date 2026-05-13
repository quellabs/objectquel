<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\HydrationException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Execution\Executors\DatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonQueryExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveIdentifierRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRootIdentifierType;
	use Quellabs\ObjectQuel\Planner\ExecutionPlanBuilder;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\Execution\Executors\DryRunDatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Planner\PlanLog;
	use Quellabs\ObjectQuel\Planner\QueryPlan;
	
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
		private QueryOptimizer $optimizer;
		private QueryNormalizer $queryNormalizer;
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
			// Init the capabilities class for engine specific optimizations
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->capabilities = new PlatformCapabilities($this->connection);

			// Create specialized executors
			$this->databaseExecutor = $databaseExecutor ?? new DatabaseQueryExecutor($entityManager, $this->capabilities);
			$this->jsonExecutor = new JsonQueryExecutor();
			
			// Init the plan executor
			$this->planExecutor = new PlanExecutor($this);
			
			// Init the transformers
			$this->optimizer = new QueryOptimizer($entityManager, $this->capabilities);
			$this->queryNormalizer = new QueryNormalizer($entityManager->getEntityStore());
			$this->semanticAnalyser = new SemanticAnalyzer($entityManager->getEntityStore());
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
		 * Returns the database executor
		 * @return DatabaseQueryExecutor
		 */
		public function getDatabaseExecutor(): DatabaseQueryExecutor {
			return $this->databaseExecutor;
		}
		
		/**
		 * Return the JSON executor
		 * @return JsonQueryExecutor
		 */
		public function getJsonExecutor(): JsonQueryExecutor {
			return $this->jsonExecutor;
		}
		
		/**
		 * Executes a query and returns the hydrated result.
		 * To inspect planner decisions without executing, use explain() instead.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return QuelResult
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): QuelResult {
			try {
				// Normalize parameters
				$normalizedParameters = $this->normalizeParams($parameters);
				
				// Clear SQL list
				$this->databaseExecutor->resetLastExecutedSql();
				
				// Parse the input query string into an Abstract Syntax Tree (AST)
				$ast = $this->parse($query);
				
				// Resolve all identifier types. Note: this does no semantic checking.
				// It just flags the type based on AST hierarchy
				$this->resolveAndSetIdentifierTypes($ast);
				
				// Processing phase #1 - Transform and enhance the AST
				$this->queryNormalizer->transform($ast);
				
				// Validation phase - Ensure AST integrity and correctness
				$this->semanticAnalyser->validate($ast);
				
				// Processing phase #2 - Transform and enhance the AST
				$this->optimizer->transform($ast, $normalizedParameters);
				
				// Decompose the query
				$planner = new ExecutionPlanBuilder();
				$executionPlan = $planner->build($ast, $normalizedParameters);
				
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
		 * Runs the planning pipeline and returns a log of every decision made.
		 * Used internally by explainQuery(); not part of the public API.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return PlanLog Planning decisions in pipeline order
		 * @throws QuelException
		 */
		private function explain(string $query, array $parameters = []): PlanLog {
			try {
				// Normalize parameters to string keys, matching executeQuery behavior
				$normalizedParameters = $this->normalizeParams($parameters);
				
				// Parse and resolve identifiers
				$ast = $this->parse($query);
				$this->resolveAndSetIdentifierTypes($ast);
				
				// Normalize and validate the AST before handing it to the optimizer
				$this->queryNormalizer->transform($ast);
				$this->semanticAnalyser->validate($ast);
				
				// Run the optimizer and planner with an active log so every decision is recorded
				$log = new PlanLog();
				$this->optimizer->transform($ast, $normalizedParameters, $log);
				
				// Build the execution plan
				$executionPlanBuilder = new ExecutionPlanBuilder();
				$executionPlanBuilder->build($ast, $normalizedParameters, $log);
				
				// Return the log — the query itself is never executed
				return $log;
			} catch (ParserException|LexerException $e) {
				throw new QuelException("Syntax error: " . $e->getMessage(), 'syntax_error', 0, $e);
			} catch (SemanticException $e) {
				throw new QuelException($e->getMessage(), 'semantic_error', 0, $e);
			} catch (TransformationException $e) {
				throw new QuelException($e->getMessage(), 'transformation_error', 0, $e);
			} catch (EntityResolutionException $e) {
				throw new QuelException($e->getMessage(), 'resolution_error', 0, $e);
			}
		}
		
		/**
		 * Returns planner decisions and generated SQL for a query without executing it.
		 * Combines explain() with a SQL dry-run into one coherent result.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return QueryPlan Planning decisions and generated SQL
		 * @throws QuelException
		 */
		public function explainQuery(string $query, array $parameters = []): QueryPlan {
			// Collect planner decisions by running the optimization pipeline
			$log = $this->explain($query, $parameters);
			
			// Run the full pipeline again through a dry-run executor to capture
			// generated SQL without touching the database. The dry-run is cheap
			// since it skips all I/O.
			$dryRun = new DryRunDatabaseQueryExecutor($this->entityManager, $this->capabilities);
			$dryRunExecutor = new self($this->entityManager, $dryRun);
			$dryRunExecutor->executeQuery($query, $parameters);
			
			return new QueryPlan($log->getNotes(), $dryRun->getCapturedSql());
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
		 * Walk through all identifiers and set their type
		 * @param AstRetrieve $retrieve
		 * @return void
		 */
		private function resolveAndSetIdentifierTypes(AstRetrieve $retrieve): void {
			// First, recursively set types all nested queries in temporary ranges
			// This ensures inner queries are fully resolved before outer query processing
			foreach ($retrieve->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseSubquery) {
					$this->resolveAndSetIdentifierTypes($range->getQuery());
				}
			}
			
			// Then set types on current query
			$retrieve->accept(new ResolveRootIdentifierType($retrieve));
			$retrieve->accept(new ResolvePropertyType());
			$retrieve->accept(new ResolveIdentifierRange($retrieve));
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