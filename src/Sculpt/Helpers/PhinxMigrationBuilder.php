<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
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
	 *
	 * A column definition is an associative array with keys such as:
	 *   type, limit, nullable, default, precision, scale, unsigned, identity,
	 *   primary_key, values (for enums)
	 *
	 * An indexConfig is an associative array with keys:
	 *   columns (string[]), type ('INDEX'|'UNIQUE'|'FULLTEXT'), unique (bool, optional)
	 *
	 * @phpstan-type ColumnDefinition array{
	 *     type: string,
	 *     limit?: int|string,
	 *     nullable?: bool,
	 *     default?: mixed,
	 *     precision?: int,
	 *     scale?: int,
	 *     unsigned?: bool,
	 *     identity?: bool,
	 *     primary_key?: bool,
	 *     values?: array<int, string>
	 * }
	 *
	 * @phpstan-type IndexConfig array{
	 *     columns: array<int, string>,
	 *     type: string,
	 *     unique?: bool
	 * }
	 *
	 * @phpstan-type IndexChanges array{
	 *     added: array<string, IndexConfig>,
	 *     modified: array<string, array{entity: IndexConfig, database: IndexConfig}>,
	 *     deleted: array<string, IndexConfig>
	 * }
	 *
	 * @phpstan-type TableChanges array{
	 *     table_not_exists?: bool,
	 *     added?: array<string, ColumnDefinition>,
	 *     modified?: array<string, array{from: ColumnDefinition, to: ColumnDefinition, changes?: array<string, array{from: mixed, to: mixed}>}>,
	 *     deleted?: array<string, ColumnDefinition>,
	 *     indexes?: IndexChanges
	 * }
	 *
	 * @phpstan-type AllChanges array<string, TableChanges>
	 */
	class PhinxMigrationBuilder {
		
		/** @var DatabaseAdapter Database connection used for live schema queries (e.g. existing primary keys) */
		private DatabaseAdapter $connection;
		
		/** @var string Absolute path to the directory where migration files are written */
		private string $migrationsPath;
		
		/**
		 * @param DatabaseAdapter $adapter Active database connection
		 * @param string $migrationsPath Directory that will receive the generated file
		 */
		public function __construct(DatabaseAdapter $adapter, string $migrationsPath) {
			$this->connection = $adapter;
			$this->migrationsPath = $migrationsPath;
		}
		
		// -------------------------------------------------------------------------
		// Public API
		// -------------------------------------------------------------------------
		
		/**
		 * Generate a Phinx migration file from a set of schema changes.
		 *
		 * The file is written to $migrationsPath with the format:
		 *   20250603145623_EntitySchemaMigration.php
		 *
		 * @param AllChanges $allChanges Table-keyed change descriptors (see class docblock)
		 * @return array{success: bool, message: string, path?: string}
		 */
		public function generateMigrationFile(array $allChanges): array {
			if (empty($allChanges)) {
				return ['success' => false, 'message' => 'No changes detected. Migration file not created.'];
			}
			
			$className = 'EntitySchemaMigration';
			$filename = $this->migrationsPath . '/' . date('YmdHis') . '_' . $className . '.php';
			
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
			$up = [];
			$down = [];
			
			foreach ($allChanges as $tableName => $changes) {
				$changes = $this->normalizeChanges($changes);
				
				if ($changes['table_not_exists']) {
					// New table: up creates it, down drops it entirely
					$up[] = $this->buildCreateTableCode($tableName, $changes['added'], $changes['indexes']['added']);
					$down[] = "        \$this->table('{$tableName}')->drop()->save();";
					continue;
				}
				
				// Column changes — down is always the mirror image of up
				if (!empty($changes['added'])) {
					$up[] = $this->buildAddColumnsCode($tableName, $changes['added']);
					$down[] = $this->buildRemoveColumnsCode($tableName, $changes['added']);
				}
				
				if (!empty($changes['modified'])) {
					$up[] = $this->buildChangeColumnsCode($tableName, $changes['modified'], 'to');
					$down[] = $this->buildChangeColumnsCode($tableName, $changes['modified'], 'from');
				}
				
				if (!empty($changes['deleted'])) {
					$up[] = $this->buildRemoveColumnsCode($tableName, $changes['deleted']);
					$down[] = $this->buildAddColumnsCode($tableName, $changes['deleted']);
				}
				
				// Index changes — down is the mirror image of up
				if (!empty($changes['indexes']['added'])) {
					$up[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['added']);
					$down[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['added']);
				}
				
				if (!empty($changes['indexes']['modified'])) {
					$up[] = $this->buildModifyIndexesCode($tableName, $changes['indexes']['modified']);
					// Swap entity/database sides so down() restores the previous index state
					$down[] = $this->buildModifyIndexesCode($tableName, $this->invertIndexModifications($changes['indexes']['modified']));
				}
				
				if (!empty($changes['indexes']['deleted'])) {
					$up[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['deleted']);
					$down[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['deleted']);
				}
			}
			
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
		 *     modified: array<string, array{from: ColumnDefinition, to: ColumnDefinition}>,
		 *     deleted: array<string, ColumnDefinition>,
		 *     indexes: IndexChanges,
		 *     table_not_exists: bool
		 * }
		 */
		private function normalizeChanges(array $changes): array {
			$defaults = [
				'added'            => [],
				'modified'         => [],
				'deleted'          => [],
				'indexes'          => ['added' => [], 'modified' => [], 'deleted' => []],
				'table_not_exists' => false,
			];
			
			$merged = array_merge($defaults, $changes);
			$merged['indexes'] = array_merge($defaults['indexes'], $changes['indexes'] ?? []);
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
				$builder->addIndex($indexConfig['columns'], $this->buildIndexOptions($indexName, $indexConfig));
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
		 * @param array<string, array{from: ColumnDefinition, to: ColumnDefinition}> $modifiedColumns
		 * @param string $direction 'to' for up(), 'from' for down()
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
				$builder->addIndex($indexConfig['columns'], $this->buildIndexOptions($name, $indexConfig));
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
			
			foreach (array_keys($indexes) as $name) {
				$builder->removeIndexByName($name);
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
				$builder->removeIndexByName($name);
				
				$builder->addIndex(
					$configs['entity']['columns'],
					$this->buildIndexOptions($name, $configs['entity'])
				);
			}
			
			return $builder->update();
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
			
			if (isset($definition['unsigned'])) {
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
		 * Build the Phinx options array for an index configuration.
		 *
		 * Maps INDEX / UNIQUE / FULLTEXT type strings to the correct Phinx addIndex()
		 * options. The index name is always included so Phinx can reference it later
		 * for removal (removeIndexByName relies on having a known name).
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