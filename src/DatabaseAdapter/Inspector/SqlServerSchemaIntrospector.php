<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * SQL Server schema introspection: columns and foreign keys via
	 * INFORMATION_SCHEMA/sys.* catalog views. SQL Server exposes no
	 * equivalent of MySQL/Postgres' index usage statistics through this
	 * codebase's existing queries, so getIndexUsageStatistics() always
	 * returns null. See objectquel-phinx-removal-plan.md for the
	 * getColumns() design and per-type mapping rationale, including the
	 * deliberate datetime2/NVARCHAR(MAX) behavior change over Phinx's own
	 * SQL Server adapter.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	readonly class SqlServerSchemaIntrospector implements SchemaIntrospectorInterface {

		/**
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $adapter;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Reads column definitions for a table via INFORMATION_SCHEMA.COLUMNS,
		 * joined with COLUMNPROPERTY(..., 'IsIdentity') for identity — the
		 * same expression Phinx's own SQL Server adapter used. Assumes the
		 * default 'dbo' schema, matching every other sqlsrv code path in this
		 * codebase (see DatabaseAdapter::getSqlServerExtendedProperty()).
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					c.COLUMN_NAME AS column_name,
					c.DATA_TYPE AS data_type,
					c.CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
					c.NUMERIC_PRECISION AS numeric_precision,
					c.NUMERIC_SCALE AS numeric_scale,
					c.COLUMN_DEFAULT AS column_default,
					c.IS_NULLABLE AS is_nullable,
					COLUMNPROPERTY(OBJECT_ID(c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') AS is_identity
				FROM INFORMATION_SCHEMA.COLUMNS c
				WHERE c.TABLE_NAME = :tableName
				ORDER BY c.ORDINAL_POSITION
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			$primaryKey = $this->adapter->getPrimaryKeyColumns($tableName);
			$result = [];

			/** @var array{column_name: string, data_type: string, character_maximum_length: string|null, numeric_precision: string|null, numeric_scale: string|null, column_default: string|null, is_nullable: string, is_identity: string|int} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$charLimit = $row['character_maximum_length'] !== null ? (int)$row['character_maximum_length'] : null;
				$type = NativeColumnTypeMapper::sqlServerType($row['data_type'], $charLimit);

				// Only 'decimal' ever carries an explicit precision/scale in
				// this ORM's own DDL — see the matching comment in
				// PostgresSchemaIntrospector::getColumns().
				$precision = $type === 'decimal' && $row['numeric_precision'] !== null ? (int)$row['numeric_precision'] : null;
				$scale = $type === 'decimal' && $row['numeric_scale'] !== null ? (int)$row['numeric_scale'] : null;

				$limit = match ($type) {
					'string', 'char' => $charLimit !== null && $charLimit >= 0 ? $charLimit : null,
					default => TypeMapper::getDefaultLimit($type),
				};

				$result[$row['column_name']] = new ColumnDefinition(
					type: $type,
					php_type: TypeMapper::phinxTypeToPhpType($type),
					limit: $limit,
					default: $this->normalizeSqlServerDefault($row['column_default']),
					nullable: $row['is_nullable'] === 'YES',
					precision: $precision,
					scale: $scale,
					unsigned: false,
					generated: null,
					identity: (int)$row['is_identity'] === 1,
					primary_key: in_array($row['column_name'], $primaryKey, true),
					values: null,
				);
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table via sys.foreign_keys /
		 * sys.foreign_key_columns, which stores one row per column pair natively
		 * (constraint_column_id gives the correct ordinal), so composite
		 * constraints round-trip correctly with no pairing ambiguity.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					fk.name AS constraint_name,
					pc.name AS column_name,
					fkc.constraint_column_id AS ordinal_position,
					rt.name AS referenced_table,
					rc.name AS referenced_column,
					fk.delete_referential_action_desc AS delete_rule,
					fk.update_referential_action_desc AS update_rule
				FROM sys.foreign_keys fk
				JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
				JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
				JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
				JOIN sys.tables t ON t.object_id = fk.parent_object_id
				JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
				WHERE t.name = :tableName
				ORDER BY fk.name, fkc.constraint_column_id
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			$byName = [];

			/** @var array{constraint_name: string, column_name: string, ordinal_position: int|string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byName[$row['constraint_name']][(int)$row['ordinal_position']] = $row;
			}

			$result = [];

			foreach ($byName as $name => $rows) {
				ksort($rows);
				$first = reset($rows);

				$result[$name] = new ForeignKeyDefinition(
					columns: array_column($rows, 'column_name'),
					referencedTable: $first['referenced_table'],
					referencedColumns: array_column($rows, 'referenced_column'),
					// SQL Server uses underscores (NO_ACTION, SET_NULL) where every
					// other engine uses spaces; normalize for a consistent string diff.
					onDelete: str_replace('_', ' ', $first['delete_rule']),
					onUpdate: str_replace('_', ' ', $first['update_rule']),
				);
			}

			return $result;
		}

		/**
		 * SQL Server exposes no equivalent index usage statistics through
		 * this codebase's existing queries.
		 * @param string[] $tables
		 * @return array<string, array<string, IndexUsageStats>>|null
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			return null;
		}
		
		/**
		 * Normalizes a SQL Server COLUMN_DEFAULT value to a plain scalar. SQL
		 * Server wraps a default in one or more layers of parentheses
		 * (`('abc')`, `((0))`), reports the literal `NULL` text for an
		 * explicit NULL default, and leaves numeric literals unquoted.
		 * @param string|null $default
		 * @return int|string|null
		 */
		private function normalizeSqlServerDefault(?string $default): int|string|null {
			if ($default === null) {
				return null;
			}
			
			$value = trim($default);
			
			while (strlen($value) >= 2 && str_starts_with($value, '(') && str_ends_with($value, ')')) {
				$value = substr($value, 1, -1);
			}
			
			if (preg_match("/^'(.*)'$/s", $value, $matches) === 1) {
				return str_replace("''", "'", $matches[1]);
			}
			
			if (strcasecmp($value, 'NULL') === 0) {
				return null;
			}
			
			if (is_numeric($value)) {
				return (int)$value;
			}
			
			return $value;
		}
	}
