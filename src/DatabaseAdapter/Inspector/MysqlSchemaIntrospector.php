<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NumericPrecisionScale;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * MySQL/MariaDB schema introspection: columns via native per-engine SQL
	 * (see objectquel-phinx-removal-plan.md — this replaced Phinx's
	 * AdapterInterface::getColumns()), foreign keys via
	 * information_schema.KEY_COLUMN_USAGE/REFERENTIAL_CONSTRAINTS, index
	 * usage stats via performance_schema.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	readonly class MysqlSchemaIntrospector implements SchemaIntrospectorInterface {

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
		 * Reads column definitions for a table via information_schema.COLUMNS
		 * — structured metadata already split into DATA_TYPE/
		 * CHARACTER_MAXIMUM_LENGTH/NUMERIC_PRECISION/NUMERIC_SCALE/
		 * COLUMN_TYPE/EXTRA/COLUMN_DEFAULT, no SHOW FULL COLUMNS regex parsing
		 * needed the way Phinx's own MySQL adapter did it.
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					COLUMN_NAME AS column_name,
					DATA_TYPE AS data_type,
					COLUMN_TYPE AS column_type,
					CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
					COLUMN_DEFAULT AS column_default,
					IS_NULLABLE AS is_nullable,
					EXTRA AS extra
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName
				ORDER BY ORDINAL_POSITION
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			$primaryKey = $this->adapter->getPrimaryKeyColumns($tableName);
			$result = [];

			/** @var array{column_name: string, data_type: string, column_type: string, character_maximum_length: string|null, column_default: string|null, is_nullable: string, extra: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$charLimit = $row['character_maximum_length'] !== null ? (int)$row['character_maximum_length'] : null;
				$type = NativeColumnTypeMapper::mysqlType($row['data_type'], $row['column_type'], $charLimit);
				$values = $type === 'enum' ? $this->parseMysqlEnumValues($row['column_type']) : null;
				$precisionScale = $type === 'decimal' ? $this->parseMysqlPrecisionScale($row['column_type']) : new NumericPrecisionScale(null, null);

				$limit = match (true) {
					$type === 'enum' => $this->resolveEnumLimit($values),
					$type === 'string' || $type === 'char' => $charLimit,
					$type === 'binary' => $charLimit ?? TypeMapper::getDefaultLimit('binary'),
					default => TypeMapper::getDefaultLimit($type),
				};

				$result[$row['column_name']] = new ColumnDefinition(
					type: $type,
					php_type: TypeMapper::phinxTypeToPhpType($type),
					limit: $limit,
					default: $this->normalizeMysqlDefault($row['column_default']),
					nullable: $row['is_nullable'] === 'YES',
					precision: $precisionScale->precision,
					scale: $precisionScale->scale,
					unsigned: str_contains(strtolower($row['column_type']), 'unsigned'),
					generated: null,
					identity: strtolower($row['extra']) === 'auto_increment',
					primary_key: in_array($row['column_name'], $primaryKey, true),
					values: $values,
				);
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table.
		 *
		 * KEY_COLUMN_USAGE alone maps columns to the referenced table/column but doesn't
		 * carry the ON DELETE/UPDATE action, so it's joined against REFERENTIAL_CONSTRAINTS
		 * (matched on CONSTRAINT_NAME + CONSTRAINT_SCHEMA) to get the actual delete/update rule.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					kcu.CONSTRAINT_NAME AS constraint_name,
					kcu.COLUMN_NAME AS column_name,
					kcu.ORDINAL_POSITION AS ordinal_position,
					kcu.REFERENCED_TABLE_NAME AS referenced_table,
					kcu.REFERENCED_COLUMN_NAME AS referenced_column,
					rc.DELETE_RULE AS delete_rule,
					rc.UPDATE_RULE AS update_rule
				FROM information_schema.KEY_COLUMN_USAGE kcu
				JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
				WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = :tableName AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
				ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			// Accumulated as plain arrays first, not ForeignKeyDefinition directly —
			// a composite constraint spans multiple rows (one per column), and
			// 'columns'/'referencedColumns' are appended to across iterations,
			// which a readonly value object can't support in place.
			$raw = [];

			/** @var array{constraint_name: string, column_name: string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$name = $row['constraint_name'];

				if (!isset($raw[$name])) {
					$raw[$name] = [
						'columns'           => [],
						'referencedTable'   => $row['referenced_table'],
						'referencedColumns' => [],
						'onDelete'          => $row['delete_rule'],
						'onUpdate'          => $row['update_rule'],
					];
				}

				$raw[$name]['columns'][] = $row['column_name'];
				$raw[$name]['referencedColumns'][] = $row['referenced_column'];
			}

			$result = [];

			foreach ($raw as $name => $definition) {
				$result[$name] = new ForeignKeyDefinition(...$definition);
			}

			return $result;
		}

		/**
		 * Reads index usage stats from
		 * performance_schema.table_io_waits_summary_by_index_usage (0/0 means
		 * unused since the last restart, not "no data"). Returns null when the
		 * query fails, e.g. performance_schema disabled.
		 * @param string[] $tables
		 * @return array<string, array<string, IndexUsageStats>>|null
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			// Interpolated, not bound: performance_schema rejects prepared-statement
			// binding on some MySQL/MariaDB versions. Names come from DatabaseAdapter::getTables().
			$quoter = new SqlIdentifierQuoter(new PlatformCapabilities($this->adapter));
			$inList = implode(', ', array_map(fn(string $t) => $quoter->quoteStringLiteral($t), $tables));

			$statement = $this->adapter->execute("
				SELECT
					OBJECT_NAME AS table_name,
					INDEX_NAME AS index_name,
					COUNT_READ AS `reads`,
					COUNT_WRITE AS `writes`
				FROM performance_schema.table_io_waits_summary_by_index_usage
				WHERE OBJECT_SCHEMA = DATABASE() AND
				      OBJECT_NAME IN ({$inList}) AND
				      INDEX_NAME IS NOT NULL
			");

			if ($statement === null) {
				return null;
			}

			$result = [];

			/** @var array{table_name: string, index_name: string, reads: int, writes: int} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$result[$row['table_name']][$row['index_name']] = [
					'reads'  => (int)$row['reads'],
					'writes' => (int)$row['writes'],
				];
			}

			return $result;
		}
		
		
		/**
		 * Extracts enum case values out of a MySQL COLUMN_TYPE string, e.g.
		 * "enum('a','b')" -> ['a', 'b']. Handles doubled single quotes, MySQL's
		 * own escaping convention for a literal quote inside an enum value.
		 * @param string $columnType
		 * @return array<int, string>
		 */
		private function parseMysqlEnumValues(string $columnType): array {
			if (preg_match('/^enum\((.*)\)$/i', $columnType, $outer) !== 1) {
				return [];
			}
			
			preg_match_all("/'((?:[^']|'')*)'/", $outer[1], $matches);
			
			return array_map(
				static fn(string $value): string => str_replace("''", "'", $value),
				$matches[1]
			);
		}
		
		/**
		 * Parses an explicit (precision,scale) or (precision) suffix out of a
		 * MySQL COLUMN_TYPE string, e.g. "decimal(10,2)" -> precision 10, scale 2. Deliberately
		 * not using information_schema.NUMERIC_PRECISION/NUMERIC_SCALE directly —
		 * MySQL populates those for FLOAT/DOUBLE too (e.g. 12/null for a plain
		 * FLOAT with no declared width), which DDLTypeMapper never renders and
		 * the entity side never declares, so trusting them would produce a
		 * spurious diff on every FLOAT column forever. Only DECIMAL/NUMERIC ever
		 * reach this method (see getColumns()), and DDLTypeMapper always
		 * renders those with an explicit (p,s), so parsing COLUMN_TYPE directly
		 * is both sufficient and exact.
		 * @param string $columnType
		 * @return NumericPrecisionScale
		 */
		private function parseMysqlPrecisionScale(string $columnType): NumericPrecisionScale {
			if (preg_match('/\((\d+)(?:,(\d+))?\)/', $columnType, $matches) !== 1) {
				return new NumericPrecisionScale(null, null);
			}
			
			return new NumericPrecisionScale((int)$matches[1], isset($matches[2]) ? (int)$matches[2] : 0);
		}
		
		/**
		 * Strips MySQL's charset-introducer-plus-escaped-quotes wrapping that
		 * COLUMN_DEFAULT sometimes carries for a TEXT/BLOB/JSON column's default
		 * expression, e.g. "_utf8mb4\'abc\'" -> "abc".
		 * @param string|null $default
		 * @return string|null
		 */
		private function normalizeMysqlDefault(?string $default): ?string {
			if ($default === null) {
				return null;
			}
			
			if (preg_match('/^_[A-Za-z0-9]+\\\\\'(.*)\\\\\'$/s', $default, $matches) === 1) {
				return $matches[1];
			}
			
			return $default;
		}
		
		/**
		 * Computes the storage limit for a native enum column. Must match
		 * TypeMapper::enumFallbackLimit() exactly — the same formula
		 * @Orm\Column's own getLimit() uses — or this could never match its
		 * own entity's declaration. MySQL/MariaDB-only: no other engine
		 * ever populates 'values' (see getColumns() above).
		 * @param array<int, string>|null $values Enum case values
		 * @return int Limit to use for the column definition
		 */
		private function resolveEnumLimit(?array $values): int {
			return TypeMapper::enumFallbackLimit($values ?? []);
		}
	}
