<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLUpsert;
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
	 */
	class AppendExecutor {

		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private QuelToSQLAppend $compiler;

		/**
		 * AppendExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param EntityManager $entityManager
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(
			DatabaseAdapter $connection,
			EntityStore $entityStore,
			EntityManager $entityManager,
			PlatformCapabilitiesInterface $platform
		) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->entityManager = $entityManager;

			// QuelToSQLReplace is reused (not reconstructed) so upsert's
			// on-conflict UPDATE SET clause is built by the exact same
			// property/type/@Orm\Version-bump rules a standalone `replace`
			// uses — see QuelToSQLReplace::buildSetClause(). QuelToSQLUpsert
			// itself isn't a compiler for its own AST node (there's no
			// AstUpsert — see QuelToSQLAppend's docblock); it just keeps the
			// on-conflict dialect-branching logic out of QuelToSQLAppend.
			$replaceCompiler = new QuelToSQLReplace($entityStore, $platform, $entityManager->getUnitOfWork()->getVersionValueHandler());
			$upsertCompiler = new QuelToSQLUpsert($entityStore, $platform, $replaceCompiler);
			$this->compiler = new QuelToSQLAppend($entityStore, $entityManager, $platform, $upsertCompiler);
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
			$entityName = $statement->getEntityName();
			$metadata = $entityName !== null ? $this->entityStore->getMetadata($entityName) : null;
			$generatedId = null;

			if ($metadata !== null) {
				[$statement, $generatedId] = $this->fillGeneratedPrimaryKeys($statement, $metadata, $parameters);
			}

			$sql = $this->compiler->convertToSQL($statement, $parameters);

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->connection->execute($sql, $parameters);

			if ($rs === null) {
				$target = $metadata !== null ? $metadata->tableName : $statement->getTableName();

				throw new QuelException(
					"Failed to append to '{$target}': {$this->connection->getLastErrorMessage()}",
					'append_error'
				);
			}

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

			if ($generatedId === null && $eligibleForReadback && !$statement->isInsertFromSelect() && count($statement->getRows()) === 1) {
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

			$rows = $statement->getRows();
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
