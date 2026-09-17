<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;

	/**
	 * Generates migration files from schema change descriptors, as real
	 * ObjectQuel DDL statements run through AbstractMigration::query(). The
	 * $allChanges array passed to generateMigrationFile() is keyed by table
	 * name, each value a change descriptor (see the TableChanges/AllChanges
	 * phpstan types below).
	 *
	 * Per table: a new table becomes one `create tableName (...)` statement
	 * with any new indexes embedded in its column list; column/primary-key/
	 * index changes on an existing table become one combined `alter
	 * tableName (add ..., drop ..., retype ..., primary key (...), add
	 * index ..., drop index ...)` statement — all real grammar the
	 * executor already knows how to compile. Foreign keys are a deliberate
	 * second pass over every table, after every table/column/index change,
	 * so a table a new FK references is guaranteed to already exist.
	 *
	 * `enum(...)` is always emitted verbatim — QuelToSQLCreate/QuelToSQLAlter
	 * pick native ENUM vs. VARCHAR at DDL-compile time — and 'json' is
	 * always the canonical type name emitted. The one platform-aware step
	 * is the reverse of that for JSON: a modified/deleted column's raw
	 * introspected type can legitimately be the platform's native JSON type
	 * name (e.g. 'jsonb' on PostgreSQL, since SchemaComparator returns
	 * un-normalized definitions there), so resolveType() recognizes that
	 * back to 'json' before rendering.
	 *
	 * @phpstan-import-type ColumnModification from SculptTypes
	 * @phpstan-import-type IndexDefinition from SculptTypes
	 * @phpstan-import-type IndexChangeSet from SculptTypes
	 * @phpstan-import-type ForeignKeyChangeSet from SculptTypes
	 * @phpstan-import-type PrimaryKeyChangeSet from SculptTypes
	 *
	 * @phpstan-type IndexConfig IndexDefinition
	 * @phpstan-type IndexChanges IndexChangeSet
	 * @phpstan-type ForeignKeyConfig ForeignKeyDefinition
	 * @phpstan-type ForeignKeyChanges ForeignKeyChangeSet
	 *
	 * @phpstan-type TableChanges array{
	 *     table_not_exists?: bool,
	 *     added?: array<string, ColumnDefinition>,
	 *     modified?: array<string, ColumnModification>,
	 *     deleted?: array<string, ColumnDefinition>,
	 *     indexes?: IndexChanges,
	 *     foreignKeys?: ForeignKeyChanges,
	 *     primaryKey?: PrimaryKeyChangeSet
	 * }
	 *
	 * @phpstan-type AllChanges array<string, TableChanges>
	 */
	class QuelMigrationBuilder {

		/** @var DatabaseAdapter Database connection used for live schema queries (existing primary keys, row counts) */
		private DatabaseAdapter $connection;

		/** @var string Absolute path to the directory where migration files are written */
		private string $migrationsPath;

		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;

		/** @var SqlIdentifierQuoter */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * @param DatabaseAdapter $adapter Active database connection
		 * @param string $migrationsPath Directory that will receive the generated file
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(DatabaseAdapter $adapter, string $migrationsPath, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->connection = $adapter;
			$this->migrationsPath = $migrationsPath;
			$this->platform = $platform;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		// -------------------------------------------------------------------------
		// Public API
		// -------------------------------------------------------------------------

		/**
		 * Generate a migration file from a set of schema changes.
		 *
		 * The file is written to $migrationsPath with the format:
		 *   20250603145623_QuelSchemaMigration20250603145623.php
		 *
		 * @param AllChanges $allChanges Table-keyed change descriptors (see class docblock)
		 * @return array{success: bool, message: string, path?: string}
		 * @throws \RuntimeException If a non-nullable added column has no safe backfill value (see buildAddColumnOp())
		 */
		public function generateMigrationFile(array $allChanges): array {
			if (empty($allChanges)) {
				return ['success' => false, 'message' => 'No changes detected. Migration file not created.'];
			}

			// Create the migrations directory if it doesn't exist yet.
			// The double is_dir() check guards against a race condition where another
			// process creates the directory between our check and our mkdir() call.
			if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true) && !is_dir($this->migrationsPath)) {
				return ['success' => false, 'message' => 'Failed to create migrations directory.'];
			}

			// date('YmdHis') only has one-second resolution — two calls within the
			// same second (a fast script, or just two quick manual runs) would
			// otherwise collide on both the filename and the version number.
			// file_put_contents() would then silently overwrite the earlier
			// migration file with the new one's content, but MigrationLocator/
			// QuelMigrateCommand would still see that version as already applied
			// (from the first run) and skip it forever — the schema change in
			// the overwritten file would never actually run, with no error
			// anywhere. Bumping past any version that already has a file on disk
			// guarantees a fresh, always-increasing version every call.
			//
			// A file-only check isn't enough within one long-lived process: a
			// version whose file was ever require_once'd (even a failed
			// migration since deleted) keeps its class name declared for good,
			// so reusing that version would make require_once() silently skip
			// the new file and run the stale class body instead.
			$date = (int)date('YmdHis');

			while (glob($this->migrationsPath . '/' . $date . '_*.php') !== [] || class_exists("QuelSchemaMigration{$date}", false)) {
				$date++;
			}

			$className = "QuelSchemaMigration{$date}";
			$filename = $this->migrationsPath . '/' . $date . '_' . $className . '.php';

			if (file_put_contents($filename, $this->buildMigrationContent($className, $allChanges)) === false) {
				return ['success' => false, 'message' => 'Failed to create migration file.'];
			}

			return ['success' => true, 'message' => 'Migration file created', 'path' => $filename];
		}

		// -------------------------------------------------------------------------
		// Migration file assembly
		// -------------------------------------------------------------------------

		/**
		 * Build the full PHP source code for the migration file.
		 * @param string $className Class name embedded in the generated file
		 * @param AllChanges $allChanges Table-keyed change descriptors
		 * @return string Complete PHP source ready to write to disk
		 */
		private function buildMigrationContent(string $className, array $allChanges): string {
			$normalized = [];

			foreach ($allChanges as $tableName => $changes) {
				$normalized[$tableName] = $this->normalizeChanges($changes);
			}

			$up = [];
			$downColumnsAndIndexes = [];
			$downForeignKeys = [];
			$downDropTables = [];

			// Pass 1: tables, columns, and indexes. Foreign keys are deliberately
			// left for pass 2 below — see class docblock.
			foreach ($normalized as $tableName => $changes) {
				if ($changes['table_not_exists']) {
					[$embeddedIndexes, $deferredIndexes] = $this->splitIndexesForNewTable($changes['indexes']['added']);

					// Engines with no ALTER TABLE FK support (SQLite) reject
					// pass 2's `alter (add foreign key ...)` even for a table
					// this same migration just created, so there the FK must
					// be declared inline at create time instead.
					$embedForeignKeysInCreate = !$this->platform->supportsNamedForeignKeys();
					$createForeignKeys = $embedForeignKeysInCreate ? $changes['foreignKeys']['added'] : [];

					$up[] = $this->queryStatement($this->buildCreateTableStatement($tableName, $changes['added'], $embeddedIndexes, $createForeignKeys));

					// A deferred index (always fulltext — see
					// splitIndexesForNewTable()) is a separate object (e.g. an
					// FTS5 virtual table) with no lifecycle tie to the base
					// table's own DROP TABLE, so — unlike an embedded index —
					// it needs its own explicit down() cleanup, run before the
					// table drop below while it still resolves.
					foreach ($deferredIndexes as $indexName => $indexConfig) {
						$up[] = $this->queryStatement("alter {$tableName} (" . $this->buildAddIndexOp($indexName, $indexConfig) . ")");
						$downColumnsAndIndexes[] = $this->queryStatement("alter {$tableName} (" . $this->buildDropIndexOp($indexName) . ")");
					}

					// Table drop is deferred to the very end of down() — see below —
					// so it runs after any foreign key pointing at it has already
					// been undone.
					$downDropTables[] = $this->queryStatement("destroy {$tableName}");

					if ($embedForeignKeysInCreate) {
						// Already declared inline above — pass 2 must not
						// also add these via `alter`.
						$normalized[$tableName]['foreignKeys']['added'] = [];
					}

					continue;
				}

				$alterUp = $this->buildAlterColumnsOps($tableName, $changes, 'up');
				$alterDown = $this->buildAlterColumnsOps($tableName, $changes, 'down');

				if ($alterUp !== []) {
					$up[] = $this->queryStatement("alter {$tableName} (" . implode(', ', $alterUp) . ")");
				}

				if ($alterDown !== []) {
					$downColumnsAndIndexes[] = $this->queryStatement("alter {$tableName} (" . implode(', ', $alterDown) . ")");
				}
			}

			// Pass 2: foreign keys, once every table (new or existing) is guaranteed to exist.
			foreach ($normalized as $tableName => $changes) {
				$foreignKeys = $changes['foreignKeys'];

				if (!empty($foreignKeys['added'])) {
					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildAddForeignKeyOps($foreignKeys['added'])));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildDropForeignKeyOps($foreignKeys['added'])));
				}

				if (!empty($foreignKeys['modified'])) {
					$entitySide = array_map(static fn(array $configs) => $configs['entity'], $foreignKeys['modified']);
					$databaseSide = array_map(static fn(array $configs) => $configs['database'], $foreignKeys['modified']);

					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement(
						$tableName,
						[...$this->buildDropForeignKeyOps($databaseSide), ...$this->buildAddForeignKeyOps($entitySide)]
					));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement(
						$tableName,
						[...$this->buildDropForeignKeyOps($entitySide), ...$this->buildAddForeignKeyOps($databaseSide)]
					));
				}

				if (!empty($foreignKeys['deleted'])) {
					$up[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildDropForeignKeyOps($foreignKeys['deleted'])));
					$downForeignKeys[] = $this->queryStatement($this->buildAlterForeignKeysStatement($tableName, $this->buildAddForeignKeyOps($foreignKeys['deleted'])));
				}
			}

			// down() must undo in the exact reverse of up(): foreign keys first — a
			// column or table this migration constrains can't be altered or dropped
			// while that constraint still exists — then column/index changes, then
			// finally the tables this migration created, in reverse of creation
			// order (a table with an inline FK, e.g. on SQLite, has no separate
			// drop-foreign-key step, so a referenced parent can't drop first).
			$down = [...$downForeignKeys, ...$downColumnsAndIndexes, ...array_reverse($downDropTables)];

			$upBody = implode("\n", $up);
			$downBody = implode("\n", $down);

			return <<<PHP
<?php

use Quellabs\ObjectQuel\Migration\AbstractMigration;

class $className extends AbstractMigration {

    /**
     * This migration was automatically generated by ObjectQuel
     */

    public function up(): void {
$upBody
    }

    public function down(): void {
$downBody
    }
}
PHP;
		}

		/**
		 * Fills in default values for keys a change descriptor may omit.
		 * @param TableChanges $changes Raw change descriptor, possibly missing optional keys
		 * @return array{
		 *     added: array<string, ColumnDefinition>,
		 *     modified: array<string, ColumnModification>,
		 *     deleted: array<string, ColumnDefinition>,
		 *     indexes: IndexChanges,
		 *     foreignKeys: ForeignKeyChanges,
		 *     primaryKey: PrimaryKeyChangeSet,
		 *     table_not_exists: bool
		 * }
		 */
		private function normalizeChanges(array $changes): array {
			$defaults = [
				'added'            => [],
				'modified'         => [],
				'deleted'          => [],
				'indexes'          => ['added' => [], 'modified' => [], 'deleted' => []],
				'foreignKeys'      => ['added' => [], 'modified' => [], 'deleted' => []],
				'primaryKey'       => ['action' => null],
				'table_not_exists' => false,
			];

			$merged = array_merge($defaults, $changes);
			$merged['indexes'] = array_merge($defaults['indexes'], $changes['indexes'] ?? []);
			$merged['foreignKeys'] = array_merge($defaults['foreignKeys'], $changes['foreignKeys'] ?? []);
			// PrimaryKeyChangeSet is a discriminated union, not a partial shape
			// with optional keys — there's nothing to merge key-by-key the way
			// indexes/foreignKeys need, so the incoming value (already a
			// complete, valid shape) is taken as-is.
			$merged['primaryKey'] = $changes['primaryKey'] ?? ['action' => null];
			return $merged;
		}

		/**
		 * Wraps a Quel statement as a PHP source line calling
		 * AbstractMigration::query(). addslashes() here is the second of two
		 * escaping layers — the first (QuelLiteralEscaper) already ran on
		 * any literal spliced into $quel; this escapes the *whole* resulting
		 * Quel text for its own PHP single-quoted string literal.
		 * @param string $quel
		 * @return string
		 */
		private function queryStatement(string $quel): string {
			return "        \$this->query('" . addslashes($quel) . "');";
		}

		// -------------------------------------------------------------------------
		// Table-level statement builders
		// -------------------------------------------------------------------------

		/**
		 * Splits a new table's added indexes into those safe to embed
		 * directly in its `create` statement and those that must instead
		 * become a separate, follow-up `alter` statement.
		 *
		 * A fulltext index on SQLite (FTS5) or SQL Server (KEY INDEX) needs
		 * to look up the table's own schema to compile (SQLite: its primary
		 * key; SQL Server: an existing unique/primary index) — a lookup
		 * that runs at compile time, before `create` has actually executed,
		 * so it would target a table that doesn't exist yet if embedded.
		 * Deferred to its own statement afterward, the lookup succeeds.
		 * Every other index needs no such lookup and is always safe to embed.
		 * @param array<string, IndexConfig> $indexes
		 * @return array{0: array<string, IndexConfig>, 1: array<string, IndexConfig>} [embedded, deferred]
		 */
		private function splitIndexesForNewTable(array $indexes): array {
			$embedded = [];
			$deferred = [];
			$needsDeferral = in_array($this->platform->getDatabaseType(), ['sqlite', 'sqlsrv'], true);

			foreach ($indexes as $indexName => $indexConfig) {
				if ($needsDeferral && strtoupper($indexConfig['type']) === 'FULLTEXT') {
					$deferred[$indexName] = $indexConfig;
				} else {
					$embedded[$indexName] = $indexConfig;
				}
			}

			return [$embedded, $deferred];
		}

		/**
		 * Build a `create tableName (...)` statement for a brand-new table,
		 * with any new indexes embedded in its column list (`create`'s
		 * grammar accepts index entries alongside columns), and — only
		 * when the caller passes any, see the "embed foreign keys" comment
		 * in buildMigrationContent() — any foreign keys embedded the same
		 * way.
		 * @param string $tableName
		 * @param array<string, ColumnDefinition> $columns
		 * @param array<string, IndexConfig> $indexes
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 * @return string
		 */
		private function buildCreateTableStatement(string $tableName, array $columns, array $indexes = [], array $foreignKeys = []): string {
			$primaryKeys = $this->analyzeColumns($columns)['primaryKeys'];

			$columnDefs = [];

			foreach ($columns as $columnName => $definition) {
				$columnDefs[] = $this->renderColumnDefinition($columnName, $definition);
			}

			if ($primaryKeys !== []) {
				$columnDefs[] = 'primary key (' . implode(', ', $primaryKeys) . ')';
			}

			foreach ($indexes as $indexName => $indexConfig) {
				$columnDefs[] = $this->renderIndexClause($indexName, $indexConfig);
			}

			foreach ($foreignKeys as $config) {
				$columnDefs[] = $this->renderForeignKeyClause($config);
			}

			return "create {$tableName} (" . implode(', ', $columnDefs) . ')';
		}

		/**
		 * Build the comma-separated list of `alter`'s column/primary-key/
		 * index sub-operations for one table, in one direction. `add` ops
		 * precede `primary key (...)` so a newly-added key column exists
		 * first; index ops are appended last, but position doesn't affect
		 * execution order — AlterTableExecutor always runs column/PK ops
		 * before index ops regardless of declared order.
		 * @param string $tableName
		 * @param array{added: array<string, ColumnDefinition>, modified: array<string, ColumnModification>, deleted: array<string, ColumnDefinition>, indexes?: IndexChanges, primaryKey?: PrimaryKeyChangeSet} $changes
		 * @param 'up'|'down' $direction
		 * @return list<string>
		 * @throws \RuntimeException If a non-nullable added column has no safe backfill value (up() only — see buildAddColumnOp())
		 */
		private function buildAlterColumnsOps(string $tableName, array $changes, string $direction): array {
			$ops = [];
			$primaryKeyChange = $changes['primaryKey'] ?? ['action' => null];
			$indexChanges = $changes['indexes'] ?? ['added' => [], 'modified' => [], 'deleted' => []];

			if ($direction === 'up') {
				foreach ($changes['added'] as $columnName => $definition) {
					$ops[] = $this->buildAddColumnOp($tableName, $columnName, $definition);
				}

				foreach ($changes['modified'] as $columnName => $modification) {
					$ops[] = 'retype ' . $this->renderColumnDefinition($columnName, $modification['to']);
				}

				foreach (array_keys($changes['deleted']) as $columnName) {
					$ops[] = "drop {$columnName}";
				}

				// Placed after `add`/`retype`/`drop` so a newly-added or
				// renamed-in-place primary-key column already exists by the
				// time this runs (see PrimaryKeyComparator — the desired key
				// is the entity's full current declaration, not just columns
				// this particular migration happens to be adding).
				if ($primaryKeyChange['action'] === 'set') {
					$ops[] = 'primary key (' . implode(', ', $primaryKeyChange['columns']) . ')';
				} elseif ($primaryKeyChange['action'] === 'drop') {
					$ops[] = 'drop primary key';
				}

				foreach ($indexChanges['added'] as $indexName => $indexConfig) {
					$ops[] = $this->buildAddIndexOp($indexName, $indexConfig);
				}

				foreach ($indexChanges['modified'] as $indexName => $configs) {
					$ops[] = $this->buildDropIndexOp($indexName);
					$ops[] = $this->buildAddIndexOp($indexName, $configs['entity']);
				}

				foreach (array_keys($indexChanges['deleted']) as $indexName) {
					$ops[] = $this->buildDropIndexOp($indexName);
				}
			} else {
				// down(): mirror image of up() — added columns get dropped,
				// deleted columns get added back, modifications revert to
				// their pre-migration definition, and the primary key (if it
				// changed) reverts to whatever PrimaryKeyComparator captured
				// as 'from'. Restoring a key that referenced a column this
				// migration's up() dropped needs that column back first, so
				// in that one case the restore runs last instead of first —
				// every other case restores it before any column is touched,
				// so a column this migration's up() added (and might still
				// be a member of the CURRENT key) is freed from the key
				// before being dropped.
				$previousPrimaryKey = $primaryKeyChange['from'] ?? [];
				$restoreNeedsReaddedColumn = array_intersect($previousPrimaryKey, array_keys($changes['deleted'])) !== [];

				if ($primaryKeyChange['action'] !== null && !$restoreNeedsReaddedColumn) {
					$ops[] = $previousPrimaryKey === [] ? 'drop primary key' : 'primary key (' . implode(', ', $previousPrimaryKey) . ')';
				}

				foreach (array_keys($changes['added']) as $columnName) {
					$ops[] = "drop {$columnName}";
				}

				foreach ($changes['modified'] as $columnName => $modification) {
					$ops[] = 'retype ' . $this->renderColumnDefinition($columnName, $modification['from']);
				}

				foreach ($changes['deleted'] as $columnName => $definition) {
					$ops[] = 'add ' . $this->renderColumnDefinition($columnName, $definition);
				}

				if ($primaryKeyChange['action'] !== null && $restoreNeedsReaddedColumn) {
					$ops[] = $previousPrimaryKey === [] ? 'drop primary key' : 'primary key (' . implode(', ', $previousPrimaryKey) . ')';
				}

				foreach (array_keys($indexChanges['added']) as $indexName) {
					$ops[] = $this->buildDropIndexOp($indexName);
				}

				foreach ($indexChanges['modified'] as $indexName => $configs) {
					$ops[] = $this->buildDropIndexOp($indexName);
					$ops[] = $this->buildAddIndexOp($indexName, $configs['database']);
				}

				foreach ($indexChanges['deleted'] as $indexName => $indexConfig) {
					$ops[] = $this->buildAddIndexOp($indexName, $indexConfig);
				}
			}

			return $ops;
		}

		/**
		 * Build one `add` op for a newly-added column, appending a
		 * `backfill` clause when the column is non-nullable and the table
		 * already has rows. Never needed for a brand-new table
		 * (buildCreateTableStatement() never calls this).
		 * @param string $tableName
		 * @param string $columnName
		 * @param ColumnDefinition $definition
		 * @return string
		 * @throws \RuntimeException If the column is non-nullable, the table has rows, and the entity declares no default
		 */
		private function buildAddColumnOp(string $tableName, string $columnName, ColumnDefinition $definition): string {
			$op = 'add ' . $this->renderColumnDefinition($columnName, $definition);

			if ($definition->nullable) {
				return $op;
			}

			if (!$this->tableHasRows($tableName)) {
				return $op;
			}

			if ($definition->default === null) {
				throw new \RuntimeException(
					"Cannot add non-nullable column '{$tableName}.{$columnName}': the table has existing rows and " .
					"the entity declares no default to backfill them with. Add @Orm\\Column(default=...) to the " .
					"entity, or write this migration by hand with make:blank-migration."
				);
			}

			$default = $definition->default;

			if (!is_scalar($default) && !$default instanceof \Stringable) {
				throw new \RuntimeException(
					"Cannot add non-nullable column '{$tableName}.{$columnName}': its declared default is not a " .
					"value 'backfill' can express as a string literal. Write this migration by hand with make:blank-migration."
				);
			}

			$escaped = QuelLiteralEscaper::escape((string)$default);
			return "{$op} backfill '{$escaped}'";
		}

		/**
		 * Whether $tableName currently has at least one row, checked live
		 * against the connected database.
		 * @param string $tableName
		 * @return bool
		 */
		private function tableHasRows(string $tableName): bool {
			$quotedTable = $this->identifierQuoter->quoteIdentifier($tableName);
			$statement = $this->connection->execute("SELECT COUNT(*) AS cnt FROM {$quotedTable}");

			if ($statement === null) {
				return false;
			}

			$row = $statement->fetchAssoc();
			return isset($row['cnt']) && (int)$row['cnt'] > 0;
		}

		// -------------------------------------------------------------------------
		// Index sub-op builders — embedded in `create`/`alter`, never standalone
		// -------------------------------------------------------------------------

		/**
		 * Render a `[unique|fulltext] index name (cols)` clause, shared by
		 * `create`'s embedded index entries and `alter`'s `add index` op.
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 * @return string
		 */
		private function renderIndexClause(string $indexName, array $indexConfig): string {
			$type = strtoupper($indexConfig['type']);

			$modifier = match ($type) {
				'FULLTEXT' => 'fulltext ',
				'UNIQUE' => 'unique ',
				default => '',
			};

			$columns = implode(', ', $indexConfig['columns']);
			return "{$modifier}index {$indexName} ({$columns})";
		}

		/**
		 * Build an `add [unique|fulltext] index name (cols)` sub-op for
		 * `alter`'s op list.
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 * @return string
		 */
		private function buildAddIndexOp(string $indexName, array $indexConfig): string {
			return 'add ' . $this->renderIndexClause($indexName, $indexConfig);
		}

		/**
		 * Build a `drop index name` sub-op for `alter`'s op list.
		 * @param string $indexName
		 * @return string
		 */
		private function buildDropIndexOp(string $indexName): string {
			return "drop index {$indexName}";
		}

		// -------------------------------------------------------------------------
		// Foreign-key statement builders
		// -------------------------------------------------------------------------

		/**
		 * @param string $tableName
		 * @param list<string> $ops
		 * @return string
		 */
		private function buildAlterForeignKeysStatement(string $tableName, array $ops): string {
			return "alter {$tableName} (" . implode(', ', $ops) . ')';
		}

		/**
		 * Render a `foreign key (col) references Table (col) on delete X
		 * on update Y` clause, shared by `create`'s embedded entries and
		 * `alter`'s `add foreign key` op (see buildAddForeignKeyOps()).
		 * @param ForeignKeyDefinition $config
		 * @return string
		 */
		private function renderForeignKeyClause(ForeignKeyDefinition $config): string {
			$column = $config->columns[0];
			$referencedColumn = $config->referencedColumns[0];
			$onDelete = strtolower($config->onDelete);
			$onUpdate = strtolower($config->onUpdate);

			return "foreign key ({$column}) references {$config->referencedTable} ({$referencedColumn}) on delete {$onDelete} on update {$onUpdate}";
		}

		/**
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 * @return list<string>
		 */
		private function buildAddForeignKeyOps(array $foreignKeys): array {
			return array_map(fn($config) => 'add ' . $this->renderForeignKeyClause($config), array_values($foreignKeys));
		}

		/**
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 * @return list<string>
		 */
		private function buildDropForeignKeyOps(array $foreignKeys): array {
			$ops = [];

			foreach ($foreignKeys as $config) {
				$ops[] = "drop foreign key ({$config->columns[0]})";
			}

			return $ops;
		}

		// -------------------------------------------------------------------------
		// Column helpers
		// -------------------------------------------------------------------------

		/**
		 * Render a single `name = [unsigned] type[(args)] [nullable]
		 * [identity]` column definition, matching ColumnDefinitionClause's
		 * grammar exactly (see ObjectQuel/Rules/ColumnDefinitionClause.php).
		 * @param string $columnName
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function renderColumnDefinition(string $columnName, ColumnDefinition $definition): string {
			$type = $this->resolveType($definition);

			if ($type === 'enum') {
				$typeExpr = 'enum(' . $this->renderEnumValues($definition->values ?? []) . ')';
			} else {
				$unsigned = $definition->unsigned ? 'unsigned ' : '';
				$typeExpr = $unsigned . $type . $this->renderTypeArguments($type, $definition);
			}

			$constraints = [];

			if ($definition->nullable) {
				$constraints[] = 'nullable';
			}

			if ($definition->identity) {
				$constraints[] = 'identity';
			}

			$constraintsExpr = $constraints === [] ? '' : ' ' . implode(' ', $constraints);

			return "{$columnName} = {$typeExpr}{$constraintsExpr}";
		}

		/**
		 * @param string[] $values
		 * @return string
		 */
		private function renderEnumValues(array $values): string {
			return implode(', ', array_map(
				fn(string $value) => "'" . QuelLiteralEscaper::escape($value) . "'",
				$values
			));
		}

		/**
		 * Column types DDLTypeMapper never renders a limit for, on any
		 * supported engine — each has its own fixed/native SQL type
		 * (INT, UUID, TEXT, etc.) that ignores the limit argument
		 * entirely (see DDLTypeMapper's per-dialect match arms). Emitting
		 * `(n)` for these is dead syntax that round-trips through the
		 * migration but affects no generated DDL, e.g. a uuid column's
		 * fixed 36-char length or an integer column's legacy MySQL
		 * display width. 'char' and the VARCHAR/VARBINARY fallback used
		 * for 'string'/unrecognized types are deliberately not listed
		 * here — those do consume the limit on at least one platform.
		 */
		private const array TYPES_WITHOUT_DDL_LIMIT = [
			'tinyinteger', 'smallinteger', 'integer', 'biginteger',
			'float', 'decimal',
			'boolean',
			'date', 'datetime', 'time', 'timestamp',
			'text', 'blob',
			'json',
			'uuid', 'year',
		];

		/**
		 * The `(precision,scale)` or `(limit)` type-arguments suffix —
		 * mutually exclusive in Quel's grammar, unlike Phinx's option
		 * array. Precision takes priority: a decimal-like column has
		 * precision set and no meaningful limit. The limit itself is
		 * only emitted for types DDLTypeMapper actually consults it for
		 * — see TYPES_WITHOUT_DDL_LIMIT.
		 * @param string $type Resolved column type (see resolveType())
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function renderTypeArguments(string $type, ColumnDefinition $definition): string {
			if (!empty($definition->precision)) {
				$scale = $definition->scale ?? 0;
				return "({$definition->precision},{$scale})";
			}

			if (
				!empty($definition->limit) &&
				is_int($definition->limit) &&
				!in_array($type, self::TYPES_WITHOUT_DDL_LIMIT, true)
			) {
				return "({$definition->limit})";
			}

			return '';
		}

		/**
		 * Recognizes the platform's native JSON type name back to the
		 * canonical 'json' — see class docblock.
		 * @param ColumnDefinition $definition
		 * @return string
		 */
		private function resolveType(ColumnDefinition $definition): string {
			$type = $definition->type;

			if ($type === $this->platform->getNativeJsonType() && $type !== 'json') {
				return 'json';
			}

			return $type;
		}

		/**
		 * Scan a set of column definitions and extract primary key column
		 * names, in declaration order.
		 * @param array<string, ColumnDefinition> $columns
		 * @return array{primaryKeys: list<string>}
		 */
		private function analyzeColumns(array $columns): array {
			$primaryKeys = [];

			foreach ($columns as $columnName => $definition) {
				if ($definition->primary_key) {
					$primaryKeys[] = $columnName;
				}
			}

			return ['primaryKeys' => $primaryKeys];
		}
	}
