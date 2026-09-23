<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * PostgreSQL schema introspection: columns and foreign keys via
	 * information_schema, index usage stats via pg_stat_user_indexes. See
	 * objectquel-phinx-removal-plan.md for the getColumns() design and
	 * per-type mapping rationale.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	readonly class PostgresSchemaIntrospector implements SchemaIntrospectorInterface {

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
		 * Reads column definitions for a table via information_schema.columns.
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					column_name,
					data_type,
					is_identity,
					is_nullable,
					column_default,
					character_maximum_length,
					numeric_precision,
					numeric_scale
				FROM information_schema.columns
				WHERE table_schema = current_schema() AND table_name = :tableName
				ORDER BY ordinal_position
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			$primaryKey = $this->adapter->getPrimaryKeyColumns($tableName);
			$result = [];

			/** @var array{column_name: string, data_type: string, is_identity: string, is_nullable: string, column_default: string|null, character_maximum_length: string|null, numeric_precision: string|null, numeric_scale: string|null} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$type = NativeColumnTypeMapper::postgresType($row['data_type']);
				$identity = $row['is_identity'] === 'YES';
				$charLimit = $row['character_maximum_length'] !== null ? (int)$row['character_maximum_length'] : null;

				// Only 'decimal' ever carries an explicit precision/scale in this
				// ORM's own DDL (DDLTypeMapper always renders DECIMAL(p,s) but
				// plain REAL for 'float', no width) — trusting Postgres' own
				// numeric_precision for 'float' the way it's trusted for
				// 'decimal' would report a binary precision (24/53) the entity
				// side never declares, a spurious diff forever. Same reasoning
				// as MysqlSchemaIntrospector::parseMysqlPrecisionScale().
				$precision = $type === 'decimal' && $row['numeric_precision'] !== null ? (int)$row['numeric_precision'] : null;
				$scale = $type === 'decimal' && $row['numeric_scale'] !== null ? (int)$row['numeric_scale'] : null;

				$limit = match ($type) {
					'string', 'char' => $charLimit,
					default => TypeMapper::getDefaultLimit($type),
				};

				$result[$row['column_name']] = new ColumnDefinition(
					type: $type,
					php_type: TypeMapper::phinxTypeToPhpType($type),
					limit: $limit,
					default: $this->normalizePostgresDefault($row['column_default'], $identity),
					nullable: $row['is_nullable'] === 'YES',
					precision: $precision,
					scale: $scale,
					unsigned: false,
					generated: null,
					identity: $identity,
					primary_key: in_array($row['column_name'], $primaryKey, true),
					values: null,
				);
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table via information_schema.
		 *
		 * information_schema has no ordinal linking a composite constraint's local
		 * columns to its referenced columns, so joining produces every possible
		 * pairing, not just the real ones. Since @Orm\ForeignKey only ever declares
		 * single-column constraints, a constraint resolving to more than one local
		 * column is a real composite FK and is simply not reported, rather than
		 * risk a wrongly-paired column set.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			$statement = $this->adapter->execute("
				SELECT
					tc.constraint_name AS constraint_name,
					kcu.column_name AS column_name,
					ccu.table_name AS referenced_table,
					ccu.column_name AS referenced_column,
					rc.delete_rule AS delete_rule,
					rc.update_rule AS update_rule
				FROM information_schema.table_constraints tc
				JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.constraint_schema = tc.constraint_schema
				JOIN information_schema.referential_constraints rc ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.constraint_schema
				JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
				WHERE tc.constraint_type = 'FOREIGN KEY' AND
				      tc.table_schema = current_schema() AND
				      tc.table_name = :tableName
				ORDER BY tc.constraint_name, kcu.ordinal_position
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return [];
			}

			$byName = [];

			/** @var array{constraint_name: string, column_name: string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byName[$row['constraint_name']][] = $row;
			}

			$result = [];

			foreach ($byName as $name => $rows) {
				$localColumns = array_values(array_unique(array_column($rows, 'column_name')));

				if (count($localColumns) !== 1) {
					continue;
				}

				$result[$name] = new ForeignKeyDefinition(
					columns: $localColumns,
					referencedTable: $rows[0]['referenced_table'],
					referencedColumns: [$rows[0]['referenced_column']],
					onDelete: $rows[0]['delete_rule'],
					onUpdate: $rows[0]['update_rule'],
				);
			}

			return $result;
		}

		/**
		 * Reads index usage stats from pg_stat_user_indexes. Postgres tracks
		 * writes only at the table level, so writes is reported as -1
		 * (callers render it as n/a).
		 * @param string[] $tables
		 * @return array<string, array<string, IndexUsageStats>>|null
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			$quoter = new SqlIdentifierQuoter(new PlatformCapabilities($this->adapter));
			$inList = implode(', ', array_map(fn(string $t) => $quoter->quoteStringLiteral($t), $tables));

			$statement = $this->adapter->execute("
				SELECT
					relname AS table_name,
					indexrelname AS index_name,
					idx_scan AS reads
				FROM pg_stat_user_indexes
				WHERE relname IN ({$inList})
			");

			if ($statement === null) {
				return null;
			}

			$result = [];

			/** @var array{table_name: string, index_name: string, reads: int} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$result[$row['table_name']][$row['index_name']] = [
					'reads'  => (int)$row['reads'],
					'writes' => -1, // PostgreSQL does not track per-index writes
				];
			}

			return $result;
		}
		
		/**
		 * Normalizes a PostgreSQL column_default value to a plain scalar.
		 *
		 * An identity column always returns null here regardless of what
		 * column_default reports: DDLTypeMapper renders identity columns with
		 * `GENERATED BY DEFAULT AS IDENTITY`, never the traditional
		 * `serial`/`nextval(...)` idiom, and the entity's own 'identity' flag
		 * is already authoritative for these — a function-call expression
		 * isn't meaningfully comparable to anything on the entity side. No
		 * live PostgreSQL connection was available while implementing this to
		 * confirm empirically whether column_default is even populated for a
		 * GENERATED BY DEFAULT AS IDENTITY column; returning null
		 * unconditionally is the safe choice either way, matching this
		 * codebase's "no inferred IDs" convention elsewhere.
		 * @param string|null $default
		 * @param bool $identity
		 * @return string|null
		 */
		private function normalizePostgresDefault(?string $default, bool $identity): ?string {
			if ($default === null || $identity) {
				return null;
			}
			
			// Strip a trailing explicit type cast, e.g. 'abc'::character varying
			if (preg_match('/^(.*)::[a-zA-Z0-9_ ]+(\[])?$/s', $default, $matches) === 1) {
				$default = $matches[1];
			}
			
			// Strip surrounding quotes from a string literal default
			if (preg_match("/^'(.*)'$/s", $default, $matches) === 1) {
				return str_replace("''", "'", $matches[1]);
			}
			
			return $default;
		}
	}
