<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NumericPrecisionScale;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Schema\ForeignKeyConstraintNamer;

	/**
	 * SQLite schema introspection: columns via `PRAGMA table_info()`, foreign
	 * keys via `PRAGMA foreign_key_list()`. SQLite exposes no index usage
	 * statistics, so getIndexUsageStatistics() always returns null. See
	 * objectquel-phinx-removal-plan.md for the getColumns() design and
	 * per-type mapping rationale — the identity-detection algorithm in
	 * particular is the highest-risk part of that plan.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	readonly class SqliteSchemaIntrospector implements SchemaIntrospectorInterface {

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
		 * Reads column definitions for a table via `PRAGMA table_info()`.
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			$quotedTable = $this->adapter->escapeIdentifier($tableName);
			$statement = $this->adapter->execute("PRAGMA table_info({$quotedTable})");

			if ($statement === null) {
				return [];
			}

			/** @var array<int, array{cid: int, name: string, type: string, notnull: int|string, dflt_value: string|null, pk: int|string}> $rows */
			$rows = $statement->fetchAll('assoc');
			$identityByColumn = $this->resolveSqliteIdentity($tableName, $rows);
			$primaryKey = $this->adapter->getPrimaryKeyColumns($tableName);
			$result = [];

			foreach ($rows as $row) {
				$type = NativeColumnTypeMapper::sqliteType($row['type']);
				$precisionScale = $type === 'decimal' ? $this->parseSqliteNumericPrecisionScale($row['type']) : new NumericPrecisionScale(null, null);
				$isIdentity = $identityByColumn[$row['name']] ?? false;

				$limit = match ($type) {
					'string', 'char' => $this->parseSqliteLimit($row['type']),
					default => TypeMapper::getDefaultLimit($type),
				};

				$result[$row['name']] = new ColumnDefinition(
					type: $type,
					php_type: TypeMapper::phinxTypeToPhpType($type),
					limit: $limit,
					default: $this->normalizeSqliteDefault($row['dflt_value']),
					// A rowid-alias identity column always reports notnull=0
					// here even though it can never hold NULL (inserting one
					// triggers autoincrement instead) -- taken literally,
					// this would diff as "nullable" forever.
					nullable: $isIdentity ? false : (int)$row['notnull'] === 0,
					precision: $precisionScale->precision,
					scale: $precisionScale->scale,
					unsigned: false,
					generated: null,
					identity: $isIdentity,
					primary_key: in_array($row['name'], $primaryKey, true),
					values: null,
				);
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table via PRAGMA foreign_key_list().
		 *
		 * Rows sharing the same 'id' belong to the same (possibly composite) constraint,
		 * ordered by 'seq'. SQLite assigns no constraint name, so a deterministic one is
		 * synthesized from the table and local columns — matching the naming convention
		 * MakeMigrationsCommand uses when generating constraints, so round-tripping a
		 * generated constraint back through this method compares equal.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			$quotedTable = $this->adapter->escapeIdentifier($tableName);
			$statement = $this->adapter->execute("PRAGMA foreign_key_list({$quotedTable})");

			if ($statement === null) {
				return [];
			}

			$byId = [];

			/** @var array{id: int, seq: int, table: string, from: string, to: string, on_update: string, on_delete: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byId[$row['id']]['referencedTable'] ??= $row['table'];
				$byId[$row['id']]['onDelete'] ??= strtoupper($row['on_delete']);
				$byId[$row['id']]['onUpdate'] ??= strtoupper($row['on_update']);
				$byId[$row['id']]['columns'][(int)$row['seq']] = $row['from'];
				$byId[$row['id']]['referencedColumns'][(int)$row['seq']] = $row['to'];
			}

			$result = [];

			foreach ($byId as $definition) {
				ksort($definition['columns']);
				ksort($definition['referencedColumns']);
				$definition['columns'] = array_values($definition['columns']);
				$definition['referencedColumns'] = array_values($definition['referencedColumns']);

				$name = ForeignKeyConstraintNamer::nameForColumns($tableName, $definition['columns']);
				$result[$name] = new ForeignKeyDefinition(...$definition);
			}

			return $result;
		}

		/**
		 * SQLite exposes no index usage statistics.
		 * @param string[] $tables
		 * @return array<string, array<string, IndexUsageStats>>|null
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			return null;
		}
		
		/**
		 * Determines which column (if any) on a SQLite table is a rowid-alias
		 * identity column, reproducing Phinx's own resolveIdentity() algorithm —
		 * deliberately *not* a literal `AUTOINCREMENT` keyword search. A column
		 * counts as identity when it is the table's *only* primary-key column,
		 * its declared type is exactly `integer` (case-insensitive), and the
		 * table has no autoindex with `origin = 'pk'` (which would indicate
		 * `WITHOUT ROWID` or a descending-order PK, neither eligible for
		 * rowid-alias behaviour) — a bare `id INTEGER PRIMARY KEY` is a rowid
		 * alias too, with no `AUTOINCREMENT` keyword required. See
		 * objectquel-phinx-removal-plan.md's SQLite section for why this must
		 * reproduce the general rule, not a simpler substring check:
		 * `renderSqliteColumnDefinition()` generates literal `INTEGER PRIMARY
		 * KEY AUTOINCREMENT`, and round-tripping that back through
		 * introspection to confirm identity=true is what makes re-diffing an
		 * already-created table a no-op.
		 * @param string $tableName
		 * @param array<int, array{name: string, type: string, pk: int|string}> $tableInfoRows
		 * @return array<string, bool> Column name => identity
		 */
		private function resolveSqliteIdentity(string $tableName, array $tableInfoRows): array {
			$columnNames = array_column($tableInfoRows, 'name');
			$pkRows = array_values(array_filter($tableInfoRows, static fn(array $row): bool => (int)$row['pk'] !== 0));
			
			if (count($pkRows) !== 1) {
				return array_fill_keys($columnNames, false);
			}
			
			$quotedTable = $this->adapter->escapeIdentifier($tableName);
			$indexListStatement = $this->adapter->execute("PRAGMA index_list({$quotedTable})");
			$hasPkAutoIndex = false;
			
			if ($indexListStatement !== null) {
				foreach ($indexListStatement->fetchAll('assoc') as $index) {
					if (($index['origin'] ?? null) === 'pk') {
						$hasPkAutoIndex = true;
						break;
					}
				}
			}
			
			$singlePkColumn = $pkRows[0]['name'];
			$result = [];
			
			foreach ($tableInfoRows as $row) {
				$result[$row['name']] =
					!$hasPkAutoIndex &&
					$row['name'] === $singlePkColumn &&
					strtolower($row['type']) === 'integer';
			}
			
			return $result;
		}
		
		/**
		 * Parses an explicit (n) length suffix out of a SQLite declared type
		 * string, e.g. "VARCHAR(255)" -> 255. DDLTypeMapper always renders an
		 * explicit length for 'string'/'char' (defaulting to 255 when unset),
		 * so this is always present for those two types.
		 * @param string $declaredType
		 * @return int|null
		 */
		private function parseSqliteLimit(string $declaredType): ?int {
			return preg_match('/\((\d+)\)/', $declaredType, $matches) === 1 ? (int)$matches[1] : null;
		}
		
		/**
		 * Parses an explicit (precision,scale) suffix out of a SQLite declared
		 * type string, e.g. "NUMERIC(10,2)" -> precision 10, scale 2.
		 * @param string $declaredType
		 * @return NumericPrecisionScale
		 */
		private function parseSqliteNumericPrecisionScale(string $declaredType): NumericPrecisionScale {
			if (preg_match('/\((\d+)(?:,(\d+))?\)/', $declaredType, $matches) !== 1) {
				return new NumericPrecisionScale(null, null);
			}
			
			return new NumericPrecisionScale((int)$matches[1], isset($matches[2]) ? (int)$matches[2] : 0);
		}
		
		/**
		 * Normalizes a SQLite `dflt_value` to a plain scalar. This codebase's
		 * own DDL only ever writes a plain quoted string literal default (see
		 * the `backfill` clause), never a function-call or expression default,
		 * so unlike Phinx's own general-purpose SQL-token-scanning default
		 * parser, only a surrounding quoted-string strip is needed.
		 * @param string|null $default
		 * @return string|null
		 */
		private function normalizeSqliteDefault(?string $default): ?string {
			if ($default === null) {
				return null;
			}
			
			if (preg_match("/^'(.*)'$/s", $default, $matches) === 1) {
				return str_replace("''", "'", $matches[1]);
			}
			
			return $default;
		}
	}
