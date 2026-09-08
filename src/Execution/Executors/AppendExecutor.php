<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\PlanExecutor;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\Planner\ExecutionPlanBuilder;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyFactory;

	/**
	 * Executes an AstAppend statement: fills in an auto-generated primary key
	 * for the literal-values form (mirroring what InsertPersister does for
	 * persist(), so `append` and `persist()` generate PKs identically for the
	 * same entity — see objectquel-append-plan.md), compiles it via
	 * QuelToSQLAppend, and runs the resulting INSERT directly against the
	 * connection.
	 *
	 * Bypasses the `retrieve` pipeline entirely — this is a bulk, set-based,
	 * direct-SQL statement that never goes through UnitOfWork or the identity
	 * map (see objectquel-write-verbs-design.md).
	 *
	 * A JSON-source range target is diverted to JsonAppendExecutor before any
	 * of the above — it never reaches QuelToSQLAppend/SQL at all (see
	 * objectquel-json-append-plan.md).
	 */
	class AppendExecutor {

		/**
		 * Rows per batch for planner-routed insert-from-select (see
		 * executeInsertFromSelectViaPlanner()) — mirrors
		 * TempTableExecutor::INSERT_BATCH_SIZE to avoid per-statement/packet
		 * size limits on large results.
		 */
		private const int INSERT_BATCH_SIZE = 500;

		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private QuelToSQLAppend $compiler;
		private JsonAppendExecutor $jsonAppendExecutor;
		private PlanExecutor $planExecutor;

		/**
		 * AppendExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityManager $entityManager
		 * @param PlatformCapabilitiesInterface $platform
		 * @param PlanExecutor $planExecutor Used only when an insert-from-select's
		 *        source retrieve needs JSON/temp-table materialization — see
		 *        executeInsertFromSelectViaPlanner().
		 */
		public function __construct(
			DatabaseAdapter $connection,
			EntityManager $entityManager,
			PlatformCapabilitiesInterface $platform,
			PlanExecutor $planExecutor
		) {
			$this->connection = $connection;
			$this->entityManager = $entityManager;
			$this->entityStore = $entityManager->getEntityStore();
			$this->planExecutor = $planExecutor;

			// QuelToSQLReplace is reused (not reconstructed) so upsert's
			// on-conflict UPDATE SET clause is built by the exact same
			// property/type/@Orm\Version-bump rules a standalone `replace`
			// uses — see QuelToSQLReplace::buildSetClause(). QuelToSQLUpsert
			// itself isn't a compiler for its own AST node (there's no
			// AstUpsert — see QuelToSQLAppend's docblock); it just keeps the
			// on-conflict dialect-branching logic out of QuelToSQLAppend.
			$replaceCompiler = new QuelToSQLReplace($this->entityStore, $platform, $entityManager->getUnitOfWork()->getVersionValueHandler());
			$upsertCompiler = new QuelToSQLUpsert($this->entityStore, $platform, $replaceCompiler);
			$this->compiler = new QuelToSQLAppend($entityManager, $platform, $upsertCompiler);
			$this->jsonAppendExecutor = new JsonAppendExecutor();
		}

		/**
		 * Compile and execute an `append to <range> (...)` statement.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 * @throws \ReflectionException|SemanticException
		 */
		public function execute(AstAppend $statement, array $parameters): QuelResult {
			if ($statement->getRange() instanceof AstRangeJsonSource) {
				return $this->jsonAppendExecutor->execute($statement, $parameters);
			}

			if ($statement->isInsertFromSelect()) {
				$source = $this->prepareInsertFromSelectSource($statement, $parameters);

				if ($this->compiler->needsPlanner($source)) {
					return $this->executeInsertFromSelectViaPlanner($statement, $source, $parameters);
				}
			}

			return $this->executeDirectInsert($statement, $parameters);
		}

		/**
		 * Prepares, compiles, and runs the literal-values (or planner-ineligible
		 * insert-from-select) form of an append statement directly against the
		 * connection. Split out of execute() so the JSON-source and
		 * planner-routed diversions above stay easy to read.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 * @throws \ReflectionException|SemanticException
		 */
		private function executeDirectInsert(AstAppend $statement, array $parameters): QuelResult {
			$prepared = $this->prepare($statement, $parameters);
			$statement = $prepared->getStatement();
			$metadata = $prepared->getMetadata();
			$sql = $this->compiler->convertToSQL($statement, $parameters);

			// getTableName() is nullable in general, but JSON is already
			// excluded and $metadata === null rules out entity too — must be
			// plain-table, so getTableNameOrFail() is the right accessor.
			$target = $metadata !== null ? $metadata->tableName : $statement->getTableNameOrFail();

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->assertInsertSucceeded($this->connection->execute($sql, $parameters), $target);

			// Insert ID is only unambiguous for single-row literal appends.
			// Multi-row or insert-from-select is engine-dependent, so leave it
			// null. Plain-table ranges lack metadata but can safely attempt the
			// connection-level readback; getInsertId() returns false if unavailable.
			$eligibleForReadback = $metadata === null || $metadata->autoIncrementColumn !== null;
			$generatedId = $prepared->getGeneratedId();

			if ($generatedId === null && $eligibleForReadback && !$statement->isInsertFromSelect() && count($statement->getRowsOrFail()) === 1) {
				$insertId = $this->connection->getInsertId();

				if ($insertId !== false) {
					// Matches InsertPersister's (int) cast for the same
					// identity-column read — the driver returns it as a string.
					$generatedId = (int)$insertId;
				}
			}

			return QuelResult::fromWriteStatement($rs->rowCount(), $generatedId);
		}

		/**
		 * Compiles an `append to <range> (...)` statement to SQL without
		 * running it, for QueryExecutor::explainQuery(). Applies the same
		 * generated-PK side effect on $parameters that execute() has (an
		 * identity-strategy PK still comes from the database and stays
		 * absent from both the SQL and the parameters).
		 *
		 * Not supported for a JSON-source range target — JsonAppendExecutor
		 * writes rows straight into the source file and never produces SQL,
		 * so there is nothing to compile or show.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws QuelException If the target is a JSON-source range, or on compile failure
		 * @throws \ReflectionException|SemanticException
		 */
		public function compileSql(AstAppend $statement, array &$parameters): string {
			if ($statement->getRange() instanceof AstRangeJsonSource) {
				throw new QuelException(
					"append to a JSON-source range has no SQL to explain — it writes directly to the source file",
					'not_plannable'
				);
			}

			if ($statement->isInsertFromSelect()) {
				$source = $this->prepareInsertFromSelectSource($statement, $parameters);

				if ($this->compiler->needsPlanner($source)) {
					// Row count is data-dependent — can't show static SQL
					// without running the SELECT, same reasoning as the
					// JSON-target case above.
					throw new QuelException(
						"append ... retrieve whose source requires JSON-source or temp-table materialization has no static SQL to explain — the number of INSERT statements depends on the fetched row count; run the query to see actual behavior",
						'not_plannable'
					);
				}
			}

			$statement = $this->prepare($statement, $parameters)->getStatement();
			return $this->compiler->convertToSQL($statement, $parameters);
		}

		/**
		 * Prepares the source retrieve (see QuelToSQLAppend::prepareSource()).
		 * Shared by execute()/compileSql() so the optimizer runs exactly once
		 * per statement — re-running it on an already-optimized AST isn't safe.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return AstRetrieve The statement's source retrieve, mutated in place
		 */
		private function prepareInsertFromSelectSource(AstAppend $statement, array &$parameters): AstRetrieve {
			$source = $statement->getSourceOrFail();
			$this->compiler->prepareSource($source, $parameters);
			return $source;
		}
		
		/**
		 * Handles insert-from-select when the source needs JSON-source or
		 * temp-table materialization — QuelToSQLRetrieve can't express that as
		 * a single INSERT...SELECT (it silently drops ranges it doesn't
		 * understand), so instead: run the source through
		 * ExecutionPlanBuilder/PlanExecutor like a top-level retrieve, then
		 * re-insert the fetched rows as a chunked, transactional
		 * literal-values append.
		 *
		 * Deliberately skips prepare()/fillGeneratedPrimaryKeys(): it only
		 * skips PK generation for isInsertFromSelect() statements, which a
		 * synthetic forValues() chunk isn't — routing through it would
		 * auto-generate PKs here but not on the fast path, diverging behavior
		 * for the same QUEL shape purely because JSON was involved.
		 *
		 * Known limitation: unlike a single INSERT...SELECT, the full source
		 * result is read into memory before the transaction opens — no
		 * DB-side snapshot links the SELECT and the INSERTs, and large
		 * sources aren't streamed. Unavoidable for in-memory JSON joins.
		 * @param AstAppend $statement
		 * @param AstRetrieve $source Already prepared via prepareInsertFromSelectSource()
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException|SemanticException|EntityResolutionException|\Throwable
		 */
		private function executeInsertFromSelectViaPlanner(AstAppend $statement, AstRetrieve $source, array $parameters): QuelResult {
			// The append's declared column list, e.g. `append to Foo (bar, baz) ...`
			// — positionally matched against the source's visible aliases below.
			$properties = $statement->getColumnsOrFail();

			// Two different labels, deliberately: resolveVisibleAliases()
			// wants entity class name (entity) or table name (plain-table);
			// the error message always wants the physical table name.
			$entityName = $statement->getEntityName();
			$metadata = $entityName !== null ? $this->entityStore->getMetadata($entityName) : null;
			$targetLabel = $entityName ?? $statement->getTableNameOrFail();
			$tableName = $metadata !== null ? $metadata->tableName : $statement->getTableNameOrFail();

			// Maps $properties[$i] to the source retrieve's $i-th visible
			// projection alias, so a fetched row's column ($row[$alias]) can be
			// read back out under the target property name below.
			$visibleAliases = $this->compiler->resolveVisibleAliases($properties, $source, $targetLabel);

			// Runs the source retrieve exactly like a top-level `retrieve`
			// query (JSON joins, temp-table promotion and all) and materializes
			// every row in memory — see the "known limitation" note above.
			$plan = (new ExecutionPlanBuilder())->build($source, $parameters);
			$rows = $this->planExecutor->execute($plan);

			if (empty($rows)) {
				return QuelResult::fromWriteStatement(0, null);
			}

			$totalAffected = 0;
			$this->connection->beginTrans();

			try {
				// Re-insert the fetched rows as chunked literal-values appends
				// (INSERT_BATCH_SIZE per statement) inside one transaction, so a
				// failure partway through rolls back everything instead of
				// leaving a partially-inserted result set.
				foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batchIndex => $batch) {
					$chunkParams = [];
					$assignmentRows = [];

					foreach ($batch as $rowIndex => $row) {
						$assignmentRow = [];

						foreach ($properties as $i => $property) {
							// Parameter names are namespaced by batch and row index
							// so two different chunks (or two rows in the same
							// chunk) never collide on the same bound parameter.
							$paramName = "__append_source_{$batchIndex}_{$rowIndex}_{$property}";
							$chunkParams[$paramName] = $row[$visibleAliases[$i]];
							$assignmentRow[] = new AstAssignment($property, new AstParameter($paramName));
						}

						$assignmentRows[] = $assignmentRow;
					}

					// Synthesizes a literal-values AstAppend for this chunk and
					// compiles/runs it through the same QuelToSQLAppend path as
					// executeDirectInsert() — see this method's docblock for why
					// prepare()/fillGeneratedPrimaryKeys() are deliberately
					// skipped here.
					$chunkStatement = AstAppend::forValues($statement->getRange(), $assignmentRows);
					$sql = $this->compiler->convertToSQL($chunkStatement, $chunkParams);
					$rs = $this->assertInsertSucceeded($this->connection->execute($sql, $chunkParams), $tableName);
					$totalAffected += $rs->rowCount();
				}

				$this->connection->commitTrans();
			} catch (\Throwable $e) {
				$this->connection->rollbackTrans();
				throw $e;
			}

			// No single-row insert ID to report — an insert-from-select can
			// touch many rows across many batches, so this mirrors
			// executeDirectInsert()'s isInsertFromSelect() case (id left null).
			return QuelResult::fromWriteStatement($totalAffected, null);
		}

		/**
		 * Throws when execute() returned null (its documented failure
		 * signal), naming the physical target table. Shared by execute() and
		 * executeInsertFromSelectViaPlanner() for identical failure reporting.
		 * @param StatementInterface|null $rs
		 * @param string $tableName Physical table name, for the error message
		 * @return StatementInterface The same $rs, narrowed to non-null
		 * @throws QuelException When $rs is null
		 */
		private function assertInsertSucceeded(?StatementInterface $rs, string $tableName): StatementInterface {
			if ($rs === null) {
				throw new QuelException(
					"Failed to append to '{$tableName}': {$this->connection->getLastErrorMessage()}",
					'append_error'
				);
			}

			return $rs;
		}

		/**
		 * Resolves entity metadata (when the target is a declared entity range)
		 * and fills in any generated primary keys, shared by execute() and
		 * compileSql() so both compile the exact same statement.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @throws \ReflectionException|EntityResolutionException
		 */
		private function prepare(AstAppend $statement, array &$parameters): PreparedAppend {
			$entityName = $statement->getEntityName();
			$metadata = $entityName !== null ? $this->entityStore->getMetadata($entityName) : null;

			if ($metadata === null) {
				return new PreparedAppend($statement, null, null);
			}

			return $this->fillGeneratedPrimaryKeys($statement, $metadata, $parameters);
		}

		/**
		 * For the literal-values form, generates a value for the target
		 * entity's primary key on every row that doesn't already supply one —
		 * unless the strategy is 'identity', which the database handles on
		 * its own. Uses the same PrimaryKeyFactory/generator classes
		 * persist() uses, applied to a constructor-bypassed blank instance of
		 * the target entity (the generators only use it to look up the
		 * entity's own metadata; nothing here is persisted).
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @throws \ReflectionException
		 */
		private function fillGeneratedPrimaryKeys(AstAppend $statement, EntityMetadataRecord $metadata, array &$parameters): PreparedAppend {
			// Insert-from-select has no literal rows to generate PKs into —
			// executeInsertFromSelectViaPlanner() handles its own PK story (or
			// lack of one, since it deliberately bypasses this method entirely
			// for its synthetic per-chunk statements — see that method's docblock).
			if ($statement->isInsertFromSelect()) {
				return new PreparedAppend($statement, $metadata, null);
			}

			// No declared primary key on this entity — nothing to generate.
			$primaryKey = $metadata->getPrimaryKey();

			if ($primaryKey === null) {
				return new PreparedAppend($statement, $metadata, null);
			}

			// Only the first row is checked: append's rows all share the same
			// column shape (see AstAppend), so if row 0 supplies the PK, every
			// row does.
			$rows = $statement->getRowsOrFail();
			$suppliedProperties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			// Caller already supplied a value for every row — respect it rather
			// than overwriting with a generated one.
			if (in_array($primaryKey, $suppliedProperties, true)) {
				return new PreparedAppend($statement, $metadata, null);
			}

			// 'identity' means an auto-increment/serial column — the database
			// assigns it on insert, so there's nothing to generate here (and
			// nothing to add to the SQL or $parameters).
			$strategy = $this->resolvePrimaryKeyStrategy($metadata, $primaryKey);

			if ($strategy === 'identity') {
				return new PreparedAppend($statement, $metadata, null);
			}

			// Constructor-bypassed: PrimaryKeyFactory's generators only read the
			// entity's metadata off the instance (e.g. table/column info for a
			// 'sequence' MAX(col)+1 lookup) — they never touch instance state a
			// real constructor would set up, and nothing here is persisted.
			$blankEntity = (new \ReflectionClass($metadata->className))->newInstanceWithoutConstructor();
			$factory = new PrimaryKeyFactory();

			$newRows = [];
			$firstGeneratedValue = null;

			foreach ($rows as $index => $row) {
				// Re-querying a 'sequence' strategy per row would return the same
				// "next" value for every row, since none of them are actually
				// inserted yet — bump the first row's generated value by row
				// index instead so a multi-row append doesn't collide on the
				// primary key. 'uuid' (and any other strategy) generates fresh
				// per row instead, since there's no collision to guard against.
				if ($strategy === 'sequence' && $index > 0) {
					// SequenceGenerator always generates a numeric (MAX(col)+1)
					// value — enforced here since PrimaryKeyFactory::generate()
					// is typed mixed to cover every strategy (uuid returns a
					// string, identity returns null).
					if (!is_numeric($firstGeneratedValue)) {
						throw new \LogicException("Sequence strategy produced a non-numeric primary key value for '{$primaryKey}'");
					}

					$value = $firstGeneratedValue + $index;
				} else {
					$value = $factory->generate($this->entityManager, $blankEntity, $strategy);
				}

				if ($index === 0) {
					$firstGeneratedValue = $value;
				}

				$paramName = "__append_generated_pk_{$index}";
				$parameters[$paramName] = $value;
				$row[] = new AstAssignment($primaryKey, new AstParameter($paramName));
				$newRows[] = $row;
			}

			// Matches executeDirectInsert()'s single-row-only rule for reporting
			// a generated ID — ambiguous which row's value to report otherwise.
			$generatedId = count($rows) === 1 ? $firstGeneratedValue : null;

			return new PreparedAppend(AstAppend::forValues($statement->getRange(), $newRows), $metadata, $generatedId);
		}

		/**
		 * Mirrors InsertPersister::getPrimaryKeyStrategy(), adapted to read
		 * straight from metadata instead of requiring an entity instance.
		 * @param EntityMetadataRecord $metadata
		 * @param string $primaryKey
		 * @return string
		 */
		private function resolvePrimaryKeyStrategy(EntityMetadataRecord $metadata, string $primaryKey): string {
			$annotations = $metadata->getAnnotations()[$primaryKey] ?? [];

			foreach ($annotations as $annotation) {
				if ($annotation instanceof PrimaryKeyStrategy) {
					return $annotation->getValue();
				}
			}

			return 'identity';
		}
	}