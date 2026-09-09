<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
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
	use Quellabs\ObjectQuel\Execution\Executors\AlterTableExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\AppendExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\CreateIndexExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\CreateTableExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\RetrieveExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\DeleteExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\DestroyExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\DestroyIndexExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonRetrieveExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\ReplaceExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\DateTimeParameterCoercer;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\ExecutionPlanBuilder;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\Execution\Executors\DryRunRetrieveExecutor;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLog;
	use Quellabs\ObjectQuel\Planner\QueryPlan\QueryPlan;
	
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
		private IdentifierTypeResolver $identifierTypeResolver;
		private DateTimeParameterCoercer $dateTimeParameterCoercer;
		private RetrieveExecutor $databaseExecutor;
		private JsonRetrieveExecutor $jsonExecutor;
		private CreateTableExecutor $createTableExecutor;
		private CreateIndexExecutor $createIndexExecutor;
		private AlterTableExecutor $alterTableExecutor;
		private DestroyExecutor $destroyExecutor;
		private DestroyIndexExecutor $destroyIndexExecutor;
		private AppendExecutor $appendExecutor;
		private ReplaceExecutor $replaceExecutor;
		private DeleteExecutor $deleteExecutor;
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager
		 * @param RetrieveExecutor|null $databaseExecutor
		 */
		public function __construct(
			EntityManager $entityManager,
			?RetrieveExecutor $databaseExecutor = null
		) {
			// Init the capabilities class for engine specific optimizations
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->capabilities = $this->entityManager->getUnitOfWork()->getPlatformCapabilities();
			
			// Create specialized executors
			$this->databaseExecutor = $databaseExecutor ?? new RetrieveExecutor($entityManager, $this->capabilities);
			$this->jsonExecutor = new JsonRetrieveExecutor();

			// Built before AppendExecutor, which needs it (for insert-from-select
			// sources requiring JSON/temp-table materialization) — its
			// dependencies (databaseExecutor, jsonExecutor, connection) are
			// already set above.
			$this->planExecutor = new PlanExecutor($this);

			$this->createTableExecutor = new CreateTableExecutor($this->connection, $this->capabilities);
			$this->createIndexExecutor = new CreateIndexExecutor($this->connection, $this->capabilities);
			$this->alterTableExecutor = new AlterTableExecutor($this->connection, $this->capabilities);
			$this->destroyExecutor = new DestroyExecutor($this->connection, $this->capabilities);
			$this->destroyIndexExecutor = new DestroyIndexExecutor($this->connection, $this->capabilities);
			$this->appendExecutor = new AppendExecutor($this->connection, $entityManager, $this->capabilities, $this->planExecutor);
			$this->replaceExecutor = new ReplaceExecutor($this->connection, $entityManager, $this->capabilities);
			$this->deleteExecutor = new DeleteExecutor($this->connection, $entityManager->getEntityStore(), $this->capabilities);

			// Init the transformers
			$this->optimizer = new QueryOptimizer($entityManager, $this->capabilities);
			$this->queryNormalizer = new QueryNormalizer($entityManager->getEntityStore(), $this->connection);
			$this->semanticAnalyser = new SemanticAnalyzer($entityManager->getEntityStore(), $this->capabilities, $this->connection);
			$this->identifierTypeResolver = new IdentifierTypeResolver($entityManager->getEntityStore());
			$this->dateTimeParameterCoercer = new DateTimeParameterCoercer();
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
		 * @return RetrieveExecutor
		 */
		public function getDatabaseExecutor(): RetrieveExecutor {
			return $this->databaseExecutor;
		}
		
		/**
		 * Return the JSON executor
		 * @return JsonRetrieveExecutor
		 */
		public function getJsonExecutor(): JsonRetrieveExecutor {
			return $this->jsonExecutor;
		}
		
		/**
		 * Executes a query and returns the hydrated result. Every ObjectQuel
		 * statement goes through this single entry point — `retrieve`, DDL
		 * (`create`/`destroy`/index), and the write verbs (`append`/`replace`/
		 * `delete`) alike — so slow-query logging and the development-mode
		 * debug signal in EntityManager::executeQuery() see all of them, not
		 * just `retrieve`.
		 * To inspect planner decisions without executing, use explainQuery() instead.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return QuelResult|null Null for statements with no rows to return (e.g. `create`)
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): ?QuelResult {
			try {
				// Normalize parameters
				$normalizedParameters = $this->normalizeParams($parameters);
				
				// Clear SQL list
				$this->databaseExecutor->resetLastExecutedSql();
				
				// Parse the input query string into an Abstract Syntax Tree (AST)
				$ast = $this->parse($query);
				
				// DDL statements bypass the retrieve pipeline entirely — none
				// of it applies to a statement with no rows to return.
				if (
					$ast instanceof AstCreateTable ||
					$ast instanceof AstAlterTable ||
					$ast instanceof AstDestroy ||
					$ast instanceof AstDestroyIndex ||
					$ast instanceof AstCreateIndex
				) {
					match (true) {
						$ast instanceof AstCreateTable => $this->createTableExecutor->execute($ast),
						$ast instanceof AstAlterTable => $this->alterTableExecutor->execute($ast),
						$ast instanceof AstDestroy => $this->destroyExecutor->execute($ast),
						$ast instanceof AstDestroyIndex => $this->destroyIndexExecutor->execute($ast),
						default => $this->createIndexExecutor->execute($ast),
					};

					return null;
				}
				
				// Write-verb statements bypass the retrieve pipeline entirely too
				// (no semantic analysis, no identifier resolution — see each
				// executor's own docblock) but, unlike DDL, do return a QuelResult
				// (affected-row count and, for append, a generated primary key).
				if ($ast instanceof AstAppend || $ast instanceof AstReplace || $ast instanceof AstDelete) {
					return match (true) {
						$ast instanceof AstAppend => $this->appendExecutor->execute($ast, $normalizedParameters),
						$ast instanceof AstReplace => $this->replaceExecutor->execute($ast, $normalizedParameters),
						default => $this->deleteExecutor->execute($ast, $normalizedParameters),
					};
				}
				
				// Every other AstStatement variant was handled by one of the
				// two blocks above, so this is always AstRetrieve — parse()'s
				// return type just can't say so, since AstStatement doesn't
				// enumerate its implementors.
				if (!$ast instanceof AstRetrieve) {
					throw new \LogicException('Unreachable: AstStatement is neither a DDL, write-verb, nor AstRetrieve node — ' . get_class($ast));
				}
				
				// Resolve all identifier types. Note: this does no semantic checking.
				// It just flags the type based on AST hierarchy
				$this->identifierTypeResolver->resolve($ast);

				// Processing phase #1 - Transform and enhance the AST
				$this->queryNormalizer->transform($ast);

				// Coerce parameters bound against \DateTime columns (DateTimeInterface,
				// formatted strings) into Unix timestamps, mirroring the column-side
				// conversion NormalizeDateTime just applied.
				$this->dateTimeParameterCoercer->coerce($ast, $normalizedParameters);
				
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
				return QuelResult::fromRetrieve($this->entityManager, $ast, $result);
			} catch (ParserException|LexerException $e) {
				throw new QuelException("Syntax error: " . $e->getMessage(), 'syntax_error', 0, $e);
			} catch (SemanticException $e) {
				throw new QuelException($e->getMessage(), 'semantic_error', 0, $e);
			} catch (TransformationException $e) {
				throw new QuelException($e->getMessage(), 'transformation_error', 0, $e);
			} catch (HydrationException|\DateInvalidTimeZoneException|\DateMalformedStringException $e) {
				throw new QuelException($e->getMessage(), 'hydration_error', 0, $e);
			} catch (EntityResolutionException|\ReflectionException $e) {
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
				
				// explainQuery() already rejects anything but a retrieve statement
				// before ever calling explain() — this check is a defensive
				// backstop, not a reachable path.
				if (!$ast instanceof AstRetrieve) {
					throw new QuelException("explain() only supports retrieve statements", 'not_plannable');
				}
				
				$this->identifierTypeResolver->resolve($ast);

				// Normalize and validate the AST before handing it to the optimizer
				$this->queryNormalizer->transform($ast);
				$this->dateTimeParameterCoercer->coerce($ast, $normalizedParameters);
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
		 * Returns planner decisions and generated SQL for a retrieve query
		 * without executing it. Combines explain() with a SQL dry-run into one
		 * coherent result.
		 *
		 * DDL and write-verb statements have no optimizer/planner pipeline to
		 * report decisions from, and replaying them via the retrieve
		 * pipeline's dry-run executor would re-run the write for real — so
		 * they're rejected outright rather than explained.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return QueryPlan Planning decisions and generated SQL
		 * @throws QuelException If $query isn't a retrieve statement, or on a syntax error
		 */
		public function explainQuery(string $query, array $parameters = []): QueryPlan {
			try {
				$ast = $this->parse($query);
			} catch (ParserException|LexerException $e) {
				throw new QuelException("Syntax error: " . $e->getMessage(), 'syntax_error', 0, $e);
			}

			if (!$ast instanceof AstRetrieve) {
				throw new QuelException("explain is not supported for DDL or write-verb statements", 'not_plannable');
			}

			return $this->explainRetrieveQuery($query, $parameters);
		}
		
		/**
		 * Parses a Quel query and returns its AST representation.
		 * @param string $query The Quel query string to parse
		 * @return AstStatement The parsed AST — retrieve, DDL, or write-verb
		 * @throws LexerException
		 * @throws ParserException
		 * @throws QuelException|\ReflectionException If parsing, validation, or processing fails
		 */
		private function parse(string $query): AstStatement {
			// Convert the raw query string into an Abstract Syntax Tree
			// Create a lexer to break the query string into tokens (keywords, identifiers, operators, etc.)
			$lexer = new Lexer($query);
			
			// Create a parser that takes the tokenized input and builds an Abstract Syntax Tree
			$parser = new Parser($lexer, $this->entityManager->getEntityStore());
			
			// Execute the parsing process to generate the AST representation of the query
			// This transforms the linear token sequence into a hierarchical tree structure
			$ast = $parser->parse();
			
			// Ensure the parsed AST represents a statement type this executor knows how to run
			if (!$ast instanceof AstStatement) {
				throw new QuelException("Invalid query type: expected retrieve, create, alter, destroy, index, or write-verb (append/replace/delete) operation");
			}
			
			// The AST is now fully validated
			return $ast;
		}
		
		/**
		 * Returns planner decisions and generated SQL for a retrieve query
		 * without executing it. Combines explain() with a SQL dry-run into
		 * one coherent result.
		 * @param string $query The ObjectQuel query string
		 * @param array<int|string, mixed> $parameters Query parameters
		 * @return QueryPlan Planning decisions and generated SQL
		 * @throws QuelException
		 */
		private function explainRetrieveQuery(string $query, array $parameters): QueryPlan {
			// Collect planner decisions by running the optimization pipeline
			$log = $this->explain($query, $parameters);
			
			// Run the full pipeline again through a dry-run executor to capture
			// generated SQL without touching the database. The dry-run is cheap
			// since it skips all I/O.
			$dryRun = new DryRunRetrieveExecutor($this->entityManager, $this->capabilities);
			$dryRunExecutor = new self($this->entityManager, $dryRun);
			$dryRunExecutor->executeQuery($query, $parameters);
			
			return new QueryPlan($log->getNotes(), $dryRun->getCapturedSql());
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