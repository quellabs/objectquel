<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
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
		 * Number of rows inserted per batch when an insert-from-select's
		 * source needs the planner (see executeInsertFromSelectViaPlanner()),
		 * mirroring TempTableExecutor::INSERT_BATCH_SIZE — avoids hitting
		 * per-statement/packet size limits on large fetched result sets.
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
		 * @throws \ReflectionException
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

			[$statement, $metadata, $generatedId] = $this->prepare($statement, $parameters);
			$sql = $this->compiler->convertToSQL($statement, $parameters);

			// getTableName() is nullable in general (null for an entity or
			// JSON-source range target — see its docblock), but JSON was
			// already excluded above and $metadata === null here means this
			// isn't an entity range either, so it must be a plain-table one —
			// getTableNameOrFail() is the right accessor for that already-
			// established case (see its own docblock).
			$target = $metadata !== null ? $metadata->tableName : $statement->getTableNameOrFail();

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->assertInsertSucceeded($this->connection->execute($sql, $parameters), $target);

			// An identity column's value is only unambiguous for a single-row
			// literal-values append — for multi-row appends or insert-from-select,
			// which row's ID getInsertId() would report is engine-dependent, so
			// it's left null rather than guessed at. A plain-table range has no
			// metadata to confirm an auto-increment column exists, but the
			// readback is a plain connection-level operation with no annotation
			// dependency (see objectquel-plain-table-range-plan.md's "Open
			// decisions" — recommended even without entity metadata), so it's
			// attempted unconditionally there; getInsertId() simply reports
			// false when there's nothing to report.
			$eligibleForReadback = $metadata === null || $metadata->autoIncrementColumn !== null;

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
		 * @throws \ReflectionException
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
					// A chunked, data-dependent number of INSERT statements can't be
					// shown without actually running the source SELECT — same
					// "nothing to compile or show" reasoning as the JSON-target
					// case above.
					throw new QuelException(
						"append ... retrieve whose source requires JSON-source or temp-table materialization has no static SQL to explain — the number of INSERT statements depends on the fetched row count; run the query to see actual behavior",
						'not_plannable'
					);
				}
			}

			[$statement, , ] = $this->prepare($statement, $parameters);
			return $this->compiler->convertToSQL($statement, $parameters);
		}

		/**
		 * Runs an insert-from-select statement's source retrieve through
		 * resolve/normalize/validate/optimize (see QuelToSQLAppend::prepareSource()),
		 * shared by execute() and compileSql() so both decide the fast-path-vs-
		 * planner branch off the exact same prepared AST, and so the optimizer
		 * pipeline runs exactly once per statement — running it twice on an
		 * already-optimized AST is not safe.
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
		 * Executes an insert-from-select append whose source retrieve needs
		 * JSON-source or temp-table materialization — a plain inline
		 * `INSERT ... SELECT ...` can't express that (QuelToSQLRetrieve silently
		 * drops any range it doesn't understand), so instead: run the source
		 * through the same ExecutionPlanBuilder/PlanExecutor pipeline a
		 * top-level retrieve uses, then re-insert the fetched rows as a
		 * literal-values append, chunked and wrapped in one transaction so the
		 * whole statement stays atomic despite being two separate DB
		 * round-trips instead of one.
		 *
		 * Deliberately bypasses prepare()/fillGeneratedPrimaryKeys() for the
		 * synthetic per-chunk statements: fillGeneratedPrimaryKeys() only skips
		 * PK generation when isInsertFromSelect() is true, which is false for a
		 * forValues()-built node — routing through prepare() here would
		 * therefore auto-generate PKs, silently diverging from the fast path's
		 * existing behavior (plain insert-from-select never generates PKs) for
		 * the exact same QUEL statement shape, purely because a JSON range
		 * happened to be present. Calling $this->compiler->convertToSQL()
		 * directly keeps PK handling identical to the fast path.
		 *
		 * Known limitation: unlike the single INSERT...SELECT this replaces,
		 * the entire source result set is read into PHP memory
		 * (PlanExecutor::execute()'s return is fully materialized) before any
		 * row is inserted, and that read happens before the transaction opens
		 * — there is no DB-side snapshot linking the SELECT and the INSERTs the
		 * way one atomic SQL statement provides, and very large sources are
		 * buffered rather than streamed. Inherent to supporting in-memory JSON
		 * joins at all — a JSON-joined result can't be streamed through a
		 * single SQL statement.
		 * @param AstAppend $statement
		 * @param AstRetrieve $source Already prepared via prepareInsertFromSelectSource()
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 */
		private function executeInsertFromSelectViaPlanner(AstAppend $statement, AstRetrieve $source, array $parameters): QuelResult {
			$properties = $statement->getColumnsOrFail();

			// resolveVisibleAliases() wants the same label convention its other
			// caller (compileInsertFromSelect()/compileTableInsertFromSelect())
			// uses: entity class name when entity-backed, physical table name
			// for a plain-table range. assertInsertSucceeded()'s error message
			// wants the physical table name either way, matching execute() —
			// so these are deliberately two different labels, not one reused.
			$entityName = $statement->getEntityName();
			$metadata = $entityName !== null ? $this->entityStore->getMetadata($entityName) : null;
			$targetLabel = $entityName ?? $statement->getTableNameOrFail();
			$tableName = $metadata !== null ? $metadata->tableName : $statement->getTableNameOrFail();

			$visibleAliases = $this->compiler->resolveVisibleAliases($properties, $source, $targetLabel);

			$plan = (new ExecutionPlanBuilder())->build($source, $parameters);
			$rows = $this->planExecutor->execute($plan);

			if (empty($rows)) {
				return QuelResult::fromWriteStatement(0, null);
			}

			$totalAffected = 0;
			$this->connection->beginTrans();

			try {
				foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batchIndex => $batch) {
					$chunkParams = [];
					$assignmentRows = [];

					foreach ($batch as $rowIndex => $row) {
						$assignmentRow = [];

						foreach ($properties as $i => $property) {
							$paramName = "__append_source_{$batchIndex}_{$rowIndex}_{$property}";
							$chunkParams[$paramName] = $row[$visibleAliases[$i]];
							$assignmentRow[] = new AstAssignment($property, new AstParameter($paramName));
						}

						$assignmentRows[] = $assignmentRow;
					}

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

			return QuelResult::fromWriteStatement($totalAffected, null);
		}

		/**
		 * Throws when an execute() call returned null (its documented failure
		 * signal — see execute()'s own comment on why a try/catch here would
		 * never fire), naming the physical target table. Shared by execute()
		 * and executeInsertFromSelectViaPlanner() so both statements report an
		 * insert failure identically, single-row-append or chunked-from-planner.
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
		 * @return array{0: AstAppend, 1: ?EntityMetadataRecord, 2: mixed} The (possibly rewritten)
		 *         statement, its entity metadata (null for a plain-table range), and the
		 *         generated PK value when the statement is a single row — null otherwise
		 * @throws \ReflectionException
		 */
		private function prepare(AstAppend $statement, array &$parameters): array {
			$entityName = $statement->getEntityName();
			$metadata = $entityName !== null ? $this->entityStore->getMetadata($entityName) : null;
			$generatedId = null;

			if ($metadata !== null) {
				[$statement, $generatedId] = $this->fillGeneratedPrimaryKeys($statement, $metadata, $parameters);
			}

			return [$statement, $metadata, $generatedId];
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
		 * @return array{0: AstAppend, 1: mixed} The (possibly rewritten) statement, and the
		 *         generated PK value when the statement is a single row — null otherwise
		 * @throws \ReflectionException
		 */
		private function fillGeneratedPrimaryKeys(AstAppend $statement, EntityMetadataRecord $metadata, array &$parameters): array {
			if ($statement->isInsertFromSelect()) {
				return [$statement, null];
			}

			$primaryKey = $metadata->getPrimaryKey();

			if ($primaryKey === null) {
				return [$statement, null];
			}

			$rows = $statement->getRowsOrFail();
			$suppliedProperties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			if (in_array($primaryKey, $suppliedProperties, true)) {
				return [$statement, null];
			}

			$strategy = $this->resolvePrimaryKeyStrategy($metadata, $primaryKey);

			if ($strategy === 'identity') {
				return [$statement, null];
			}

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

			$generatedId = count($rows) === 1 ? $firstGeneratedValue : null;

			return [AstAppend::forValues($statement->getRange(), $newRows), $generatedId];
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
