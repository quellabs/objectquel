<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\Capabilities\FulltextIndexStyle;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	
	/**
	 * Generates Phinx migration files from schema change descriptors.
	 *
	 * The $allChanges array passed to generateMigrationFile() is keyed by table
	 * name. Each value is a change descriptor with the following shape:
	 *
	 *   table_not_exists  bool   – true when the table needs to be created from scratch
	 *   added             array<columnName, definition>
	 *   modified          array<columnName, ['from' => definition, 'to' => definition]>
	 *   deleted           array<columnName, definition>
	 *   indexes:
	 *     added           array<indexName, indexConfig>
	 *     modified        array<indexName, ['entity' => indexConfig, 'database' => indexConfig]>
	 *     deleted         array<indexName, indexConfig>
	 *   foreignKeys:
	 *     added           array<constraintName, foreignKeyConfig>
	 *     modified        array<constraintName, ['entity' => foreignKeyConfig, 'database' => foreignKeyConfig]>
	 *     deleted         array<constraintName, foreignKeyConfig>
	 *
	 * A column definition is an associative array with keys such as:
	 *   type, limit, nullable, default, precision, scale, unsigned, identity,
	 *   primary_key, values (for enums)
	 *
	 * An indexConfig is an associative array with keys:
	 *   columns (string[]), type ('INDEX'|'UNIQUE'|'FULLTEXT'), unique (bool, optional)
	 *
	 * A foreignKeyConfig is an associative array with keys:
	 *   columns (string[]), referencedTable (string), referencedColumns (string[]),
	 *   onDelete (string), onUpdate (string)
	 *
	 * @phpstan-import-type ColumnDefinition from DatabaseAdapter
	 * @phpstan-import-type ForeignKeyDefinition from DatabaseAdapter
	 * @phpstan-import-type ColumnModification from SculptTypes
	 * @phpstan-import-type IndexDefinition from SculptTypes
	 * @phpstan-import-type IndexChangeSet from SculptTypes
	 * @phpstan-import-type ForeignKeyChangeSet from SculptTypes
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
	 *     foreignKeys?: ForeignKeyChanges
	 * }
	 *
	 * @phpstan-type AllChanges array<string, TableChanges>
	 */
	class PhinxMigrationBuilder {
		
		/** @var DatabaseAdapter Database connection used for live schema queries (e.g. existing primary keys) */
		private DatabaseAdapter $connection;
		
		/** @var string Absolute path to the directory where migration files are written */
		private string $migrationsPath;
		
		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * @param DatabaseAdapter $adapter Active database connection
		 * @param string $migrationsPath Directory that will receive the generated file
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(DatabaseAdapter $adapter, string $migrationsPath, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->connection = $adapter;
			$this->migrationsPath = $migrationsPath;
			$this->platform = $platform;
		}
		
		// -------------------------------------------------------------------------
		// Public API
		// -------------------------------------------------------------------------
		
		/**
		 * Generate a Phinx migration file from a set of schema changes.
		 *
		 * The file is written to $migrationsPath with the format:
		 *   20250603145623_QuelSchemaMigration.php
		 *
		 * @param AllChanges $allChanges Table-keyed change descriptors (see class docblock)
		 * @return array{success: bool, message: string, path?: string}
		 */
		public function generateMigrationFile(array $allChanges): array {
			if (empty($allChanges)) {
				return ['success' => false, 'message' => 'No changes detected. Migration file not created.'];
			}
			
			$date = date('YmdHis');
			$className = "QuelSchemaMigration{$date}";
			$filename = $this->migrationsPath . '/' .$date . '_' . $className . '.php';
			
			// Create the migrations directory if it doesn't exist yet.
			// The double is_dir() check guards against a race condition where another
			// process creates the directory between our check and our mkdir() call.
			if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true) && !is_dir($this->migrationsPath)) {
				return ['success' => false, 'message' => 'Failed to create migrations directory.'];
			}
			
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
		 *
		 * Iterates over all change descriptors and delegates to the appropriate
		 * code-generator for each change type. Both the forward (up) and reverse
		 * (down) method bodies are built in a single pass.
		 *
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

			// Pass 1: tables, columns and indexes. Foreign keys are deliberately left
			// out of table creation here — a table with a foreign key must be created
			// after the table it references, and emitting every create() first, then
			// every addForeignKey() in a second pass (below), sidesteps needing a
			// dependency-ordering algorithm entirely.
			foreach ($normalized as $tableName => $changes) {
				if ($changes['table_not_exists']) {
					$up[] = $this->buildCreateTableCode($tableName, $changes['added'], $changes['indexes']['added']);
					// Table drop is deferred to the very end of down() — see below — so
					// it runs after any foreign key pointing at (or added to) it has
					// already been undone.
					$downDropTables[] = "        \$this->table('{$tableName}')->drop()->save();";
					continue;
				}

				// Column changes — down is always the mirror image of up
				if (!empty($changes['added'])) {
					$up[] = $this->buildAddColumnsCode($tableName, $changes['added']);
					$downColumnsAndIndexes[] = $this->buildRemoveColumnsCode($tableName, $changes['added']);
				}

				if (!empty($changes['modified'])) {
					$up[] = $this->buildChangeColumnsCode($tableName, $changes['modified'], 'to');
					$downColumnsAndIndexes[] = $this->buildChangeColumnsCode($tableName, $changes['modified'], 'from');
				}

				if (!empty($changes['deleted'])) {
					$up[] = $this->buildRemoveColumnsCode($tableName, $changes['deleted']);
					$downColumnsAndIndexes[] = $this->buildAddColumnsCode($tableName, $changes['deleted']);
				}

				// Index changes — down is the mirror image of up
				if (!empty($changes['indexes']['added'])) {
					$up[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['added']);
					$downColumnsAndIndexes[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['added']);
				}

				if (!empty($changes['indexes']['modified'])) {
					$up[] = $this->buildModifyIndexesCode($tableName, $changes['indexes']['modified']);
					// Swap entity/database sides so down() restores the previous index state
					$downColumnsAndIndexes[] = $this->buildModifyIndexesCode($tableName, $this->invertIndexModifications($changes['indexes']['modified']));
				}

				if (!empty($changes['indexes']['deleted'])) {
					$up[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['deleted']);
					$downColumnsAndIndexes[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['deleted']);
				}
			}

			// Pass 2: foreign keys, once every table (new or existing) is guaranteed to exist.
			foreach ($normalized as $tableName => $changes) {
				$foreignKeys = $changes['foreignKeys'];

				if (!empty($foreignKeys['added'])) {
					$up[] = $this->buildAddForeignKeysCode($tableName, $foreignKeys['added']);
					$downForeignKeys[] = $this->buildRemoveForeignKeysCode($tableName, $foreignKeys['added']);
				}

				if (!empty($foreignKeys['modified'])) {
					$up[] = $this->buildModifyForeignKeysCode($tableName, $foreignKeys['modified']);
					// Swap entity/database sides so down() restores the previous constraint state
					$downForeignKeys[] = $this->buildModifyForeignKeysCode($tableName, $this->invertForeignKeyModifications($foreignKeys['modified']));
				}

				if (!empty($foreignKeys['deleted'])) {
					$up[] = $this->buildRemoveForeignKeysCode($tableName, $foreignKeys['deleted']);
					$downForeignKeys[] = $this->buildAddForeignKeysCode($tableName, $foreignKeys['deleted']);
				}
			}

			// down() must undo in the exact reverse of up(): foreign keys first — a
			// column or table this migration constrains can't be altered or dropped
			// while that constraint still exists — then column/index changes, then
			// finally the tables this migration created.
			$down = array_merge($downForeignKeys, $downColumnsAndIndexes, $downDropTables);

			$upBody = implode("\n\n", $up);
			$downBody = implode("\n\n", $down);
			
			return <<<PHP
<?php

use Phinx\Migration\AbstractMigration;

class $className extends AbstractMigration {

    /**
     * This migration was automatically generated by ObjectQuel
     *
     * More information on migrations is available on the Phinx website:
     * https://book.cakephp.org/phinx/0/en/migrations.html
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
		 * Ensure all expected keys exist in a change descriptor.
		 *
		 * Uses a two-level merge so that a partial 'indexes' array (e.g. only
		 * 'added' provided) doesn't wipe out the 'modified' and 'deleted' sub-keys
		 * that a shallow array_merge would silently discard.
		 *
		 * @param TableChanges $changes Raw change descriptor, possibly missing optional keys
		 * @return array{
		 *     added: array<string, ColumnDefinition>,
		 *     modified: array<string, ColumnModification>,
		 *     deleted: array<string, ColumnDefinition>,
		 *     indexes: IndexChanges,
		 *     foreignKeys: ForeignKeyChanges,
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
				'table_not_exists' => false,
			];

			$merged = array_merge($defaults, $changes);
			$merged['indexes'] = array_merge($defaults['indexes'], $changes['indexes'] ?? []);
			$merged['foreignKeys'] = array_merge($defaults['foreignKeys'], $changes['foreignKeys'] ?? []);
			return $merged;
		}
		
		/**
		 * Swap the 'entity' and 'database' sides of each modified-index entry.
		 *
		 * Modified indexes are stored as ['entity' => newConfig, 'database' => oldConfig].
		 * Inverting them lets buildModifyIndexesCode() reuse the same logic for both
		 * the forward and reverse migration without any special-casing.
		 *
		 * @param array<string, array{entity: IndexConfig, database: IndexConfig}> $modified
		 * @return array<string, array{entity: IndexConfig, database: IndexConfig}>
		 */
		private function invertIndexModifications(array $modified): array {
			$inverted = [];

			foreach ($modified as $name => $configs) {
				$inverted[$name] = ['entity' => $configs['database'], 'database' => $configs['entity']];
			}

			return $inverted;
		}

		/**
		 * Swap the 'entity' and 'database' sides of each modified-foreign-key entry.
		 * Mirrors invertIndexModifications() so buildModifyForeignKeysCode() can be
		 * reused unchanged for both the forward and reverse migration.
		 *
		 * @param array<string, array{entity: ForeignKeyConfig, database: ForeignKeyConfig}> $modified
		 * @return array<string, array{entity: ForeignKeyConfig, database: ForeignKeyConfig}>
		 */
		private function invertForeignKeyModifications(array $modified): array {
			$inverted = [];

			foreach ($modified as $name => $configs) {
				$inverted[$name] = ['entity' => $configs['database'], 'database' => $configs['entity']];
			}

			return $inverted;
		}
		
		// -------------------------------------------------------------------------
		// Table-level code generators
		// -------------------------------------------------------------------------
		
		/**
		 * Generate code to create a new table from scratch.
		 *
		 * Phinx's default behaviour is to add an auto-increment 'id' column, which
		 * we suppress with 'id' => false so that the entity's own column definitions
		 * fully control the table structure.
		 *
		 * If the entity has an auto-increment column that is not part of the primary
		 * key (unusual but valid), a unique index is added so that MySQL's requirement
		 * of an index on AUTO_INCREMENT columns is satisfied.
		 *
		 * @param string $tableName Table to create
		 * @param array<string, ColumnDefinition> $columns
		 * @param array<string, IndexConfig> $indexes
		 */
		private function buildCreateTableCode(string $tableName, array $columns, array $indexes = []): string {
			$result = $this->analyzeColumns($columns);
			$primaryKeys = $result['primaryKeys'];
			$autoIncrementColumn = $result['autoIncrementColumn'];
			
			// Always disable Phinx's implicit 'id' column
			$tableOptions = ["'id' => false"];
			
			if (!empty($primaryKeys)) {
				$tableOptions[] = "'primary_key' => ['" . implode("', '", $primaryKeys) . "']";
			}
			
			$builder = new MigrationCodeBuilder($tableName, $tableOptions);
			$this->applyColumnDefinitions($builder, $columns);
			
			// MySQL requires AUTO_INCREMENT columns to be covered by an index.
			// When the auto-increment column is not part of the primary key we add
			// an explicit unique index to satisfy that constraint.
			if ($autoIncrementColumn !== null && !in_array($autoIncrementColumn, $primaryKeys)) {
				$builder->addIndex([$autoIncrementColumn], $this->autoIncrementIndexOptions($tableName, $autoIncrementColumn));
			}
			
			foreach ($indexes as $indexName => $indexConfig) {
				if ($this->isPostgresFulltext($indexConfig)) {
					$this->applyPostgresFulltextIndex($builder, $tableName, $indexName, $indexConfig);
				} else {
					$this->applyStandardIndex($builder, $indexName, $indexConfig);
				}
			}
			
			return $builder->create();
		}
		
		/**
		 * Generate code to add columns to an existing table.
		 *
		 * If any of the new columns is marked as a primary key, the existing primary
		 * key is fetched from the database and merged with the new keys before issuing
		 * a changePrimaryKey() call — ensuring we don't accidentally drop existing
		 * primary key columns.
		 *
		 * @param string $tableName Table to modify
		 * @param array<string, ColumnDefinition> $columns New column definitions keyed by column name
		 */
		private function buildAddColumnsCode(string $tableName, array $columns): string {
			$result = $this->analyzeColumns($columns);
			$newPrimaryKeys = $result['primaryKeys'];
			$autoIncrementColumn = $result['autoIncrementColumn'];
			
			$builder = new MigrationCodeBuilder($tableName);
			$this->applyColumnDefinitions($builder, $columns);
			
			if (!empty($newPrimaryKeys)) {
				// Merge with existing primary keys rather than replacing them
				$existing = $this->connection->getPrimaryKeyColumns($tableName);
				$merged = array_unique(array_merge($existing, $newPrimaryKeys));
				
				if ($existing !== $merged) {
					$builder->changePrimaryKey($merged);
				}
			}
			
			// See buildCreateTableCode() for why AUTO_INCREMENT columns need a unique index
			if ($autoIncrementColumn !== null && !in_array($autoIncrementColumn, $newPrimaryKeys)) {
				$builder->addIndex([$autoIncrementColumn], $this->autoIncrementIndexOptions($tableName, $autoIncrementColumn));
			}
			
			return $builder->update();
		}
		
		/**
		 * Generate code to remove columns from a table.
		 * @param string $tableName Table to modify
		 * @param array<string, ColumnDefinition> $columns Columns to remove, keyed by column name (values are ignored)
		 */
		private function buildRemoveColumnsCode(string $tableName, array $columns): string {
			$builder = new MigrationCodeBuilder($tableName);
			
			foreach (array_keys($columns) as $columnName) {
				$builder->removeColumn($columnName);
			}
			
			return $builder->update();
		}
		
		/**
		 * Generate code to change column definitions.
		 *
		 * The same method is used for both up() and down() — the $direction parameter
		 * selects which side of each change to apply ('to' = forward, 'from' = rollback).
		 *
		 * @param string $tableName Table to modify
		 * @param array<string, ColumnModification> $modifiedColumns
		 * @param 'from'|'to' $direction 'to' for up(), 'from' for down()
		 */
		private function buildChangeColumnsCode(string $tableName, array $modifiedColumns, string $direction): string {
			$builder = new MigrationCodeBuilder($tableName);
			
			foreach ($modifiedColumns as $columnName => $changes) {
				$definition = $changes[$direction];
				$builder->changeColumn($columnName, $this->resolveType($definition), $this->buildColumnOptions($definition));
			}
			
			return $builder->update();
		}
		
		/**
		 * Generate code to add new indexes to a table.
		 * @param string $tableName Table to modify
		 * @param array<string, IndexConfig> $indexes
		 */
		private function buildAddIndexesCode(string $tableName, array $indexes): string {
			$builder = new MigrationCodeBuilder($tableName);
			
			foreach ($indexes as $name => $indexConfig) {
				if ($this->isPostgresFulltext($indexConfig)) {
					$this->applyPostgresFulltextIndex($builder, $tableName, $name, $indexConfig);
				} else {
					$this->applyStandardIndex($builder, $name, $indexConfig);
				}
			}
			
			return $builder->update();
		}
		
		/**
		 * Generate code to remove indexes from a table by name.
		 * @param string $tableName Table to modify
		 * @param array<string, IndexConfig> $indexes
		 * @return string
		 */
		private function buildRemoveIndexesCode(string $tableName, array $indexes): string {
			$builder = new MigrationCodeBuilder($tableName);
			
			foreach ($indexes as $name => $indexConfig) {
				if ($this->isPostgresFulltext($indexConfig)) {
					$this->applyPostgresFulltextDrop($builder, $tableName, $name, $indexConfig);
				} else {
					$this->applyStandardDrop($builder, $name);
				}
			}
			
			return $builder->update();
		}
		
		/**
		 * Generate code to modify existing indexes.
		 *
		 * Phinx has no native modify-index operation, so each change is emitted as
		 * a removeIndexByName() followed by an addIndex(). The 'entity' side of the
		 * config holds the target state (what the index should look like after the
		 * migration runs).
		 *
		 * @param string $tableName Table to modify
		 * @param array<string, array{entity: IndexConfig, database: IndexConfig}> $indexes
		 * @throws \InvalidArgumentException When an index entry is missing required structure
		 */
		private function buildModifyIndexesCode(string $tableName, array $indexes): string {
			$builder = new MigrationCodeBuilder($tableName);
			
			foreach ($indexes as $name => $configs) {
				if ($this->isPostgresFulltext($configs['entity'])) {
					// For PostgreSQL fulltext, drop the GIN index and generated column, then recreate
					$this->applyPostgresFulltextDrop($builder, $tableName, $name, $configs['database']);
					$this->applyPostgresFulltextIndex($builder, $tableName, $name, $configs['entity']);
				} else {
					$this->applyStandardDrop($builder, $name);
					$this->applyStandardIndex($builder, $name, $configs['entity']);
				}
			}
			
			return $builder->update();
		}
		
		// -------------------------------------------------------------------------
		// Foreign key-level code generators
		// -------------------------------------------------------------------------

		/**
		 * Generate code to add new foreign key constraints to a table.
		 * @param string $tableName Table to modify
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 */
		private function buildAddForeignKeysCode(string $tableName, array $foreignKeys): string {
			$builder = new MigrationCodeBuilder($tableName);

			foreach ($foreignKeys as $name => $config) {
				$builder->addForeignKey($config['columns'], $config['referencedTable'], $config['referencedColumns'], $this->buildForeignKeyOptions($name, $config));
			}

			return $builder->update();
		}

		/**
		 * Generate code to remove foreign key constraints from a table by name.
		 * @param string $tableName Table to modify
		 * @param array<string, ForeignKeyConfig> $foreignKeys
		 */
		private function buildRemoveForeignKeysCode(string $tableName, array $foreignKeys): string {
			$builder = new MigrationCodeBuilder($tableName);

			foreach ($foreignKeys as $name => $config) {
				$builder->dropForeignKey($config['columns'], $name);
			}

			return $builder->update();
		}

		/**
		 * Generate code to modify existing foreign key constraints.
		 *
		 * Phinx has no native modify-foreign-key operation, so each change is emitted
		 * as a dropForeignKey() followed by an addForeignKey(), mirroring how modified
		 * indexes are handled. The 'entity' side of the config holds the target state.
		 * @param string $tableName Table to modify
		 * @param array<string, array{entity: ForeignKeyConfig, database: ForeignKeyConfig}> $foreignKeys
		 */
		private function buildModifyForeignKeysCode(string $tableName, array $foreignKeys): string {
			$builder = new MigrationCodeBuilder($tableName);

			foreach ($foreignKeys as $name => $configs) {
				$builder->dropForeignKey($configs['database']['columns'], $name);
				$builder->addForeignKey($configs['entity']['columns'], $configs['entity']['referencedTable'], $configs['entity']['referencedColumns'], $this->buildForeignKeyOptions($name, $configs['entity']));
			}

			return $builder->update();
		}

		/**
		 * Build the Phinx options array for a foreign key configuration.
		 * The constraint name is always included, both so it round-trips into a
		 * deterministic name on the next diff (see DatabaseAdapter::getForeignKeys())
		 * and so dropForeignKey() can target it precisely later.
		 * @param string $name Constraint name — always emitted as 'constraint'
		 * @param ForeignKeyConfig $config
		 * @return array<int, string>
		 */
		private function buildForeignKeyOptions(string $name, array $config): array {
			return [
				"'delete' => '{$config['onDelete']}'",
				"'update' => '{$config['onUpdate']}'",
				"'constraint' => '{$name}'",
			];
		}

		// -------------------------------------------------------------------------
		// Column helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Push all column definitions in $columns onto the builder.
		 *
		 * Extracted as a shared step used by both buildCreateTableCode() and
		 * buildAddColumnsCode(), which differ only in how they finalise the builder
		 * (create() vs update()).
		 *
		 * @param MigrationCodeBuilder $builder Builder to populate
		 * @param array<string, ColumnDefinition> $columns
		 */
		private function applyColumnDefinitions(MigrationCodeBuilder $builder, array $columns): void {
			foreach ($columns as $columnName => $definition) {
				$options = $this->buildColumnOptions($definition);
				
				if (!empty($definition['identity'])) {
					$options[] = "'identity' => true";
				}
				
				$builder->addColumn($columnName, $this->resolveType($definition), $options);
			}
		}
		
		/**
		 * Resolve the Phinx column type for a definition.
		 *
		 * Databases that don't support native ENUMs (e.g. SQLite, older PostgreSQL
		 * configurations) fall back to 'string'. All other types pass through unchanged.
		 *
		 * @param ColumnDefinition $definition
		 * @return string Phinx type string
		 */
		private function resolveType(array $definition): string {
			if ($definition['type'] === 'enum' && !$this->connection->supportsNativeEnums()) {
				return 'string';
			}
			
			// The ORM canonical type is 'json'. The migration layer translates it to
			// the correct DDL type for the connected engine ('json' or 'jsonb').
			if ($definition['type'] === 'json') {
				return $this->platform->getNativeJsonType();
			}
			
			return $definition['type'];
		}
		
		/**
		 * Scan a set of column definitions and extract primary key and auto-increment metadata.
		 * @param array<string, ColumnDefinition> $columns
		 * @return array{primaryKeys: array<int, string>, autoIncrementColumn: string|null}
		 */
		private function analyzeColumns(array $columns): array {
			$primaryKeys = [];
			$autoIncrementColumn = null;
			
			foreach ($columns as $columnName => $definition) {
				if (!empty($definition['primary_key'])) {
					$primaryKeys[] = $columnName;
				}
				
				// MySQL only allows one AUTO_INCREMENT column per table, so the last
				// one encountered wins — though in practice there should only ever be one.
				if (!empty($definition['identity'])) {
					$autoIncrementColumn = $columnName;
				}
			}
			
			return ['primaryKeys' => $primaryKeys, 'autoIncrementColumn' => $autoIncrementColumn];
		}
		
		/**
		 * Build the Phinx options array for a column definition.
		 *
		 * Returns an array of pre-formatted strings (e.g. "'null' => false") that
		 * MigrationCodeBuilder expects for its $options parameters.
		 *
		 * Enum columns on databases without native enum support have their 'limit'
		 * and 'values' options suppressed, since the column is emitted as 'string'.
		 *
		 * @param ColumnDefinition $definition
		 * @return array<int, string>
		 */
		private function buildColumnOptions(array $definition): array {
			$options = [];
			$isEnum = $definition['type'] === 'enum';
			$native = $this->connection->supportsNativeEnums();
			
			// Suppress limit for native enums — MySQL derives the length from the values list
			if (!empty($definition['limit']) && (!$isEnum || !$native)) {
				$options[] = "'limit' => " . TypeMapper::formatValue($definition['limit']);
			}
			
			if (isset($definition['default'])) {
				$options[] = "'default' => " . TypeMapper::formatValue($definition['default']);
			}
			
			// Default to NOT NULL when 'nullable' is absent — explicit is safer than relying on the DB default
			if (isset($definition['nullable'])) {
				$options[] = "'null' => " . ($definition['nullable'] ? 'true' : 'false');
			} else {
				$options[] = "'null' => false";
			}
			
			if (!empty($definition['precision'])) {
				$options[] = "'precision' => " . $definition['precision'];
			}
			
			if (!empty($definition['scale'])) {
				$options[] = "'scale' => " . $definition['scale'];
			}
			
			if (isset($definition['unsigned']) && $this->platform->supportsUnsignedIntegers()) {
				// Phinx uses 'signed', which is the logical inverse of 'unsigned'
				$options[] = "'signed' => " . ($definition['unsigned'] ? 'false' : 'true');
			}
			
			if (!empty($definition['values']) && $native) {
				$escaped = array_map(fn($v) => "'" . addslashes($v) . "'", $definition['values']);
				$options[] = "'values' => [" . implode(', ', $escaped) . "]";
			}
			
			return $options;
		}
		
		// -------------------------------------------------------------------------
		// Index helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Returns true when the index is a FULLTEXT type on a PostgreSQL platform.
		 * @param IndexConfig $indexConfig
		 */
		private function isPostgresFulltext(array $indexConfig): bool {
			return strtoupper($indexConfig['type']) === 'FULLTEXT'
				&& $this->platform->getFulltextIndexStyle() === FulltextIndexStyle::Tsvector;
		}
		
		/**
		 * Emits raw SQL to create a PostgreSQL fulltext index.
		 *
		 * PostgreSQL fulltext search requires a generated tsvector column and a GIN
		 * index on that column. Neither can be expressed through Phinx's fluent API,
		 * so we fall back to $this->execute() with raw DDL.
		 *
		 * The generated column is named after the index (e.g. idx_posts_search) so
		 * it can be unambiguously dropped alongside the index during rollback.
		 *
		 * @param MigrationCodeBuilder $builder
		 * @param string $tableName
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 */
		private function applyPostgresFulltextIndex(MigrationCodeBuilder $builder, string $tableName, string $indexName, array $indexConfig): void {
			$columns = $indexConfig['columns'];
			
			// Combine columns into a single tsvector expression with coalesce to handle NULLs.
			$tsvectorExpr = implode(" || ' ' || ", array_map(
				fn($col) => "to_tsvector('english', coalesce({$col}, ''))",
				$columns
			));
			
			// Add a generated tsvector column — no Phinx equivalent for GENERATED ALWAYS AS.
			$builder->execute("ALTER TABLE {$tableName} ADD COLUMN {$indexName} tsvector GENERATED ALWAYS AS ({$tsvectorExpr}) STORED");
			
			// Let Phinx create the GIN index on the generated column.
			$builder->addIndex([$indexName], ["'type' => 'gin'", "'name' => '{$indexName}_gin'"]);
		}
		
		/**
		 * Emits DDL to drop a PostgreSQL fulltext index and its generated tsvector column.
		 * @param MigrationCodeBuilder $builder
		 * @param string $tableName
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 */
		private function applyPostgresFulltextDrop(MigrationCodeBuilder $builder, string $tableName, string $indexName, array $indexConfig): void {
			$builder->removeIndexByName("{$indexName}_gin");
			$builder->execute("ALTER TABLE {$tableName} DROP COLUMN IF EXISTS {$indexName}");
		}
		
		/**
		 * Adds a standard (non-PostgreSQL-fulltext) index to the builder.
		 * @param MigrationCodeBuilder $builder
		 * @param string $indexName
		 * @param IndexConfig $indexConfig
		 */
		private function applyStandardIndex(MigrationCodeBuilder $builder, string $indexName, array $indexConfig): void {
			$builder->addIndex($indexConfig['columns'], $this->buildIndexOptions($indexName, $indexConfig));
		}
		
		/**
		 * Removes a standard (non-PostgreSQL-fulltext) index from the builder.
		 * @param MigrationCodeBuilder $builder
		 * @param string $indexName
		 */
		private function applyStandardDrop(MigrationCodeBuilder $builder, string $indexName): void {
			$builder->removeIndexByName($indexName);
		}
		
		/**
		 * Build the Phinx options array for an index configuration.
		 *
		 * Maps INDEX / UNIQUE / FULLTEXT type strings to the correct Phinx addIndex()
		 * options. The index name is always included so Phinx can reference it later
		 * for removal (removeIndexByName relies on having a known name).
		 *
		 * Note: FULLTEXT on PostgreSQL is handled separately via applyPostgresFulltextIndex()
		 * and never reaches this method.
		 *
		 * @param string $indexName Index name — always emitted as 'name'
		 * @param IndexConfig $indexConfig
		 * @return array<int, string>
		 */
		private function buildIndexOptions(string $indexName, array $indexConfig): array {
			$type = strtoupper($indexConfig['type']);
			
			$options = ["'name' => '{$indexName}'"];
			
			match ($type) {
				'FULLTEXT' => $options[] = "'type' => 'fulltext'",
				'UNIQUE'   => $options[] = "'unique' => true",
				default    => !empty($indexConfig['unique']) && $options[] = "'unique' => true",
			};
			
			return $options;
		}
		
		/**
		 * Build the Phinx options array for the unique index that covers an
		 * AUTO_INCREMENT column that is not itself part of the primary key.
		 *
		 * MySQL requires every AUTO_INCREMENT column to be the leftmost column in
		 * some index. When the column isn't part of the primary key, we create a
		 * dedicated unique index with a deterministic name so it can be referenced
		 * by name in the down() migration if needed.
		 *
		 * @param string $tableName Table name, used to build a unique index name
		 * @param string $column Auto-increment column name
		 * @return string[]
		 */
		private function autoIncrementIndexOptions(string $tableName, string $column): array {
			return ["'unique' => true", "'name' => 'uidx_{$tableName}_{$column}'"];
		}
	}