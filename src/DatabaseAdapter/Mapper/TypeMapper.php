<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Mapper;

	/**
	 * Central mapping of abstract column types to PHP, SQL, and native engine types
	 */
	class TypeMapper {
		/** @var array<string, string> Accepted aliases for abstract column types */
		private const array TYPE_ALIASES = ['int' => 'integer'];

		/**
		 * Normalizes an abstract type name and its aliases.
		 * @param string $type Type name as written
		 * @return string Canonical column type name
		 */
		public static function normalizeType(string $type): string {
			$type = strtolower($type);
			return self::TYPE_ALIASES[$type] ?? $type;
		}

		/**
		 * Canonical abstract column types and their shared PHP/schema properties.
		 * @var array<string, array{php: string, limit?: int, properties: list<string>}>
		 */
		private const array TYPES = [
			'tinyinteger' => ['php' => 'int', 'limit' => 4, 'properties' => ['limit', 'unsigned', 'identity']],
			'smallinteger' => ['php' => 'int', 'limit' => 6, 'properties' => ['limit', 'unsigned', 'identity']],
			'integer' => ['php' => 'int', 'limit' => 11, 'properties' => ['limit', 'unsigned', 'identity']],
			'biginteger' => ['php' => 'int', 'limit' => 20, 'properties' => ['limit', 'unsigned', 'identity']],
			'string' => ['php' => 'string', 'limit' => 255, 'properties' => ['limit']],
			'char' => ['php' => 'string', 'limit' => 255, 'properties' => ['limit']],
			'text' => ['php' => 'string', 'properties' => []],
			'float' => ['php' => 'float', 'properties' => ['precision', 'unsigned']],
			'decimal' => ['php' => 'float', 'properties' => ['precision', 'scale', 'unsigned']],
			'boolean' => ['php' => 'bool', 'properties' => []],
			'date' => ['php' => '\DateTime', 'properties' => []],
			'datetime' => ['php' => '\DateTime', 'properties' => ['precision']],
			'time' => ['php' => '\DateTime', 'properties' => ['precision']],
			'timestamp' => ['php' => '\DateTime', 'properties' => ['precision', 'update']],
			'binary' => ['php' => 'string', 'limit' => 255, 'properties' => ['limit']],
			'blob' => ['php' => 'string', 'properties' => []],
			'json' => ['php' => 'array', 'properties' => []],
			'enum' => ['php' => 'string', 'properties' => ['limit', 'values']],
			'set' => ['php' => 'array', 'properties' => []],
			'uuid' => ['php' => 'string', 'properties' => []],
			'year' => ['php' => 'int', 'properties' => []],
		];

		/**
		 * Get the default limit for a column type
		 * @param string $type Column type
		 * @return int|null The default limit (null if not applicable)
		 */
		public static function getDefaultLimit(string $type): int|null {
			return self::TYPES[$type]['limit'] ?? null;
		}

		/**
		 * The VARCHAR length an `enum(...)` column falls back to on engines
		 * without a native ENUM type. A 255-character floor, not an exact
		 * fit — sizing exactly would need widening later, a dead end on
		 * SQLite (no ALTER COLUMN at all). Shared by DDLTypeMapper and
		 * SchemaComparator so the two can't drift apart.
		 * @param string[] $values Declared enum values
		 * @return int
		 */
		public static function enumFallbackLimit(array $values): int {
			return max(255, ...array_map('strlen', $values));
		}

		/**
		 * Per-engine map of a declared type to the type its DDL layer
		 * renders identically to (e.g. SQLite's 'json'/'uuid' as bare
		 * TEXT — see DDLTypeMapper), so introspection can't tell them
		 * apart. SQL Server has the same 'text'/'json' gap but isn't
		 * listed: no live instance to verify a fix against.
		 */
		private const array INTROSPECTION_COLLAPSE = [
			'sqlite' => ['json' => 'text', 'uuid' => 'text'],
		];

		/**
		 * Collapses $type per INTROSPECTION_COLLAPSE, or returns it
		 * unchanged if $databaseType has no such ambiguity.
		 * @param string $type Declared column type
		 * @param string $databaseType getDatabaseType()'s return value
		 * @return string
		 */
		public static function collapseForIntrospection(string $type, string $databaseType): string {
			return self::INTROSPECTION_COLLAPSE[$databaseType][$type] ?? $type;
		}

		/**
		 * Whether $type is a recognised abstract column type (the vocabulary
		 * @Orm\Column uses). Used by `create` to reject unknown types at parse
		 * time instead of silently falling through to VARCHAR.
		 * @param string $type Column type name (already lowercased)
		 * @return bool
		 */
		public static function isValidColumnType(string $type): bool {
			return array_key_exists($type, self::TYPES);
		}

		/**
		 * Whether $type is the kind of thing that can be signed or unsigned at
		 * all (the integer/float/decimal types) — a type-level question,
		 * independent of whether the target database engine actually has an
		 * UNSIGNED modifier (see PlatformCapabilitiesInterface::
		 * supportsUnsignedIntegers() for that). Used by `create` to reject
		 * nonsensical combinations like `name = unsigned string` at parse
		 * time, regardless of target engine.
		 * @param string $type Column type name (already lowercased)
		 * @return bool
		 */
		public static function supportsUnsigned(string $type): bool {
			return in_array('unsigned', self::TYPES[$type]['properties'] ?? [], true);
		}

		/**
		 * Convert an abstract ORM column type to a corresponding PHP type.
		 * Named after this vocabulary's origin (it's literally Phinx's own
		 * AdapterInterface::PHINX_TYPE_* constant values, passed through
		 * unchanged — see objectquel-phinx-removal-plan.md), not because this
		 * method itself depends on Phinx.
		 * @param string $phinxType The abstract column type
		 * @return string The corresponding PHP type
		 */
		public static function phinxTypeToPhpType(string $phinxType): string {
			return self::TYPES[$phinxType]['php'] ?? 'mixed';
		}

		/**
		 * Get relevant properties for column comparison based on type
		 * @param string $type
		 * @return string[]
		 */
		public static function getRelevantProperties(string $type): array {
			// Base properties all columns have. 'default' is deliberately excluded:
			// no DDL path (QuelToSQLCreate, QuelToSQLAlter's retype) ever writes a
			// column-level DEFAULT to the database — @Orm\Column(default=...) is
			// applied purely at the ORM layer (QuelToSQLAppend), so a live table's
			// introspected default can never be made to match it. Comparing it here
			// would flag every declared default as a permanent, unfixable
			// "modified column" on every make:migrations run.
			$baseProperties = ['type', 'nullable'];

			// Unknown types get no extra properties beyond the base set
			return array_merge($baseProperties, self::TYPES[$type]['properties'] ?? []);
		}

		/**
		 * Format a value for inclusion in PHP code.
		 * Unsupported values return an empty string.
		 * @param mixed $value The value to format
		 * @return string Formatted value
		 */
		public static function formatValue(mixed $value): string {
			if ($value === null) {
				return 'null';
			}

			if (is_bool($value)) {
				return $value ? 'true' : 'false';
			}

			if (is_int($value) || is_float($value)) {
				return (string)$value;
			}

			// Strings are single-quoted with internal quotes escaped
			if (is_string($value) || $value instanceof \Stringable) {
				return var_export((string)$value, true);
			}

			// Unsupported values produce empty string
			return '';
		}

		/**
		 * Extracts all enum cases from a Column annotation's enum type.
		 * @param string|null $enumType
		 * @return array<int, string> Array of enum case values for backed enums, or names for unit enums
		 */
		public static function getEnumCases(?string $enumType): array {
			// Return empty array if no enum type is defined
			if (empty($enumType)) {
				return [];
			}

			// Validate that the enum type exists and is actually an enum class
			// This prevents fatal errors if an invalid class name is passed
			if (!enum_exists($enumType)) {
				return [];
			}

			// Get all enum cases using the static cases() method
			$cases = $enumType::cases();

			// Return empty array if no cases exist
			if (empty($cases)) {
				return [];
			}

			// Check if this is a backed enum using is_subclass_of
			// BackedEnum extends UnitEnum and adds the value property
			if (is_subclass_of($enumType, \BackedEnum::class)) {
				// For backed enums, extract the scalar values
				return array_map(
					fn(\UnitEnum $case): string => $case instanceof \BackedEnum ? (string)$case->value : $case->name,
					$cases
				);
			}

			// For unit enums, extract the case names instead
			return array_map(fn(\UnitEnum $case): string => $case->name, $cases);
		}

		/**
		 * Maps a MySQL/MariaDB information_schema.COLUMNS row to an abstract
		 * column type.
		 * @param string $dataType Lowercase DATA_TYPE, e.g. 'varchar'
		 * @param string $columnType Lowercase COLUMN_TYPE, e.g. 'tinyint(1)' — needed to
		 *   distinguish boolean from tinyinteger, since DATA_TYPE alone doesn't carry the display width
		 * @param int|null $characterMaximumLength CHARACTER_MAXIMUM_LENGTH — needed to
		 *   distinguish a uuid CHAR(36) column from an ordinary char column
		 * @return string Abstract column type
		 */
		public static function mysqlType(string $dataType, string $columnType, ?int $characterMaximumLength): string {
			return match (strtolower($dataType)) {
				'varchar' => 'string',
				'char' => $characterMaximumLength === 36 ? 'uuid' : 'char',
				'text', 'tinytext', 'mediumtext', 'longtext' => 'text',
				'tinyint' => str_starts_with(strtolower($columnType), 'tinyint(1)') ? 'boolean' : 'tinyinteger',
				'smallint' => 'smallinteger',
				'int' => 'integer',
				'bigint' => 'biginteger',
				'decimal', 'numeric' => 'decimal',
				'float', 'double' => 'float',
				'date' => 'date',
				'datetime' => 'datetime',
				'time' => 'time',
				'timestamp' => 'timestamp',
				'blob', 'tinyblob', 'mediumblob', 'longblob' => 'blob',
				'varbinary', 'binary' => 'binary',
				'json' => 'json',
				'enum' => 'enum',
				'year' => 'year',
				default => throw new \RuntimeException(
					"Unrecognized MySQL column type '{$dataType}' — this ORM's DDL layer never produces it"
				),
			};
		}

		/**
		 * Maps a PostgreSQL information_schema.columns row to an abstract
		 * column type.
		 * @param string $dataType Lowercase data_type, e.g. 'character varying'
		 * @return string Abstract column type
		 */
		public static function postgresType(string $dataType): string {
			$dataType = strtolower($dataType);

			return match (true) {
				$dataType === 'character varying' => 'string',
				$dataType === 'character' => 'char',
				$dataType === 'text' => 'text',
				$dataType === 'json' || $dataType === 'jsonb' => 'json',
				$dataType === 'smallint' => 'smallinteger',
				$dataType === 'integer' => 'integer',
				$dataType === 'bigint' => 'biginteger',
				$dataType === 'numeric' || $dataType === 'decimal' => 'decimal',
				$dataType === 'real' || $dataType === 'double precision' => 'float',
				$dataType === 'bytea' => 'binary',
				$dataType === 'date' => 'date',
				str_starts_with($dataType, 'timestamp') => 'datetime',
				str_starts_with($dataType, 'time') => 'time',
				$dataType === 'boolean' => 'boolean',
				$dataType === 'uuid' => 'uuid',
				default => throw new \RuntimeException(
					"Unrecognized PostgreSQL column type '{$dataType}' — this ORM's DDL layer never produces it"
				),
			};
		}

		/**
		 * Maps a SQLite PRAGMA table_info() declared type to an abstract
		 * column type. Only needs to reverse the fixed set
		 * DDLTypeMapper::sqliteSqlColumnType() itself ever emits —
		 * not SQLite's full general-purpose type-affinity system.
		 * @param string $declaredType Raw 'type' column from PRAGMA table_info(), e.g. 'VARCHAR(255)'
		 * @return string Abstract column type
		 */
		public static function sqliteType(string $declaredType): string {
			// Strip a trailing (n) or (p,s) length/precision suffix to get the bare type name
			$base = strtoupper(trim((string)preg_replace('/\(.*$/', '', trim($declaredType))));

			return match ($base) {
				'INTEGER' => 'integer',
				'REAL' => 'float',
				'NUMERIC' => 'decimal',
				'BOOLEAN' => 'boolean',
				'DATE' => 'date',
				'DATETIME' => 'datetime',
				'TIME' => 'time',
				'TIMESTAMP' => 'timestamp',
				'TEXT' => 'text',
				'BLOB' => 'blob',
				'VARCHAR' => 'string',
				'CHAR' => 'char',
				default => throw new \RuntimeException(
					"Unrecognized SQLite column type '{$declaredType}' — this ORM's DDL layer never produces it"
				),
			};
		}

		/**
		 * Maps a SQL Server INFORMATION_SCHEMA.COLUMNS row to an abstract
		 * column type.
		 *
		 * Deliberately supports 'datetime2' and MAX-length 'nvarchar', neither
		 * of which Phinx's own SQL Server adapter recognized — this codebase's
		 * DDLTypeMapper actually renders both (DATETIME2 for datetime/timestamp,
		 * NVARCHAR(MAX) for text/json), so the old Phinx-backed getColumns()
		 * already couldn't round-trip them. A MAX-length nvarchar is reported
		 * back as 'text' — SQL Server's column metadata alone can't
		 * distinguish a 'text' column from a 'json' one, the same limitation
		 * the entity's own declared type already has to resolve via the
		 * entity being authoritative.
		 * @param string $dataType Lowercase DATA_TYPE, e.g. 'nvarchar'
		 * @param int|null $characterMaximumLength CHARACTER_MAXIMUM_LENGTH — SQL Server
		 *   reports -1 here for a MAX-length column
		 * @return string Abstract column type
		 */
		public static function sqlServerType(string $dataType, ?int $characterMaximumLength): string {
			$dataType = strtolower($dataType);

			if ($dataType === 'nvarchar' && $characterMaximumLength === -1) {
				return 'text';
			}

			return match ($dataType) {
				'nvarchar', 'varchar' => 'string',
				'char', 'nchar' => 'char',
				'text', 'ntext' => 'text',
				'int' => 'integer',
				'decimal', 'numeric' => 'decimal',
				'tinyint' => 'tinyinteger',
				'smallint' => 'smallinteger',
				'bigint' => 'biginteger',
				'real', 'float' => 'float',
				'binary', 'varbinary' => 'binary',
				'time' => 'time',
				'date' => 'date',
				'bit' => 'boolean',
				'uniqueidentifier' => 'uuid',
				'datetime2' => 'datetime',
				default => throw new \RuntimeException(
					"Unrecognized SQL Server column type '{$dataType}' — this ORM's DDL layer never produces it"
				),
			};
		}

		/**
		 * Maps QUEL cast types to SQL type tokens for the selected engine.
		 * @param string $databaseType Engine identifier
		 * @return array<string, string> Cast name to SQL target type
		 */
		public static function getSupportedCastTypes(string $databaseType): array {
			return match ($databaseType) {
				'pgsql' => [
					'int'     => 'INTEGER',
					'float'   => 'FLOAT',
					'string'  => 'TEXT',
					'decimal' => 'DECIMAL',
					'bool'    => 'BOOLEAN',
				],
				'sqlite' => [
					'int'     => 'INTEGER',
					'float'   => 'REAL',
					'string'  => 'TEXT',
					'decimal' => 'NUMERIC',
				],
				'sqlsrv' => [
					'int'     => 'INT',
					'float'   => 'FLOAT',
					'string'  => 'NVARCHAR(MAX)',
					'decimal' => 'DECIMAL',
					'bool'    => 'BIT',
				],
				// MySQL/MariaDB, and the fallback for any other database type
				default => [
					'int'     => 'SIGNED',
					'float'   => 'DOUBLE',
					'string'  => 'CHAR',
					'decimal' => 'DECIMAL',
				],
			};
		}

		/**
		 * Renders an abstract column type as SQL for the selected engine.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param string $databaseType Engine identifier
		 * @param bool $supportsUnsignedIntegers Whether unsigned SQL types are supported
		 * @param bool $supportsNativeEnums Whether inline native enums are supported
		 * @param callable(string): string $quoteLiteral Quotes an enum value as a SQL literal
		 * @return string SQL column type
		 * @throws \InvalidArgumentException When the abstract type is unknown
		 * @throws \LogicException When a known type lacks an engine mapping
		 */
		public static function sqlColumnType(array $columnDefinition, string $databaseType, bool $supportsUnsignedIntegers, bool $supportsNativeEnums, callable $quoteLiteral): string {
			if (!self::isValidColumnType($columnDefinition['type'])) {
				throw new \InvalidArgumentException("Unknown abstract column type '{$columnDefinition['type']}'");
			}

			return match ($databaseType) {
				'pgsql' => self::postgresSqlColumnType($columnDefinition),
				'sqlite' => self::sqliteSqlColumnType($columnDefinition),
				'sqlsrv' => self::sqlServerSqlColumnType($columnDefinition),
				default => self::mysqlSqlColumnType($columnDefinition, $supportsUnsignedIntegers, $supportsNativeEnums, $quoteLiteral),
			};
		}

		/**
		 * Declared enum values, defaulting to an empty list — nullable
		 * because the broader ColumnDefinition shape covers every column
		 * type, most of which never set 'values' at all.
		 * @param array{values: string[]|null} $columnDefinition
		 * @return string[]
		 */
		private static function enumValues(array $columnDefinition): array {
			return $columnDefinition['values'] ?? [];
		}

		/**
		 * MySQL/MariaDB SQL types. The unsigned suffix follows the engine capability.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @return string
		 */
		private static function mysqlSqlColumnType(array $columnDefinition, bool $supportsUnsignedIntegers, bool $supportsNativeEnums, callable $quoteLiteral): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : (TypeMapper::getDefaultLimit($columnDefinition['type']) ?? 255);
			$unsigned = ($columnDefinition['unsigned'] && $supportsUnsignedIntegers) ? ' UNSIGNED' : '';

			return match ($columnDefinition['type']) {
				'tinyinteger' => "TINYINT{$unsigned}",
				'smallinteger' => "SMALLINT{$unsigned}",
				'integer' => "INT{$unsigned}",
				'biginteger' => "BIGINT{$unsigned}",
				'float' => "FLOAT{$unsigned}",
				'decimal' => sprintf('DECIMAL(%d,%d)%s', $columnDefinition['precision'] ?? 10, $columnDefinition['scale'] ?? 0, $unsigned),
				'boolean' => 'TINYINT(1)',
				'date' => 'DATE',
				'datetime' => 'DATETIME',
				'time' => 'TIME',
				'timestamp' => 'TIMESTAMP',
				'text' => 'TEXT',
				'blob' => 'BLOB',
				'binary' => "VARBINARY({$limit})",
				'json' => 'JSON',
				'uuid' => 'CHAR(36)',
				'year' => 'YEAR',
				'char' => "CHAR({$limit})",
				'enum' => $supportsNativeEnums
					? sprintf('ENUM(%s)', implode(', ', array_map(fn(string $value) => $quoteLiteral($value), self::enumValues($columnDefinition))))
					: sprintf('VARCHAR(%d)', TypeMapper::enumFallbackLimit(self::enumValues($columnDefinition))),
				'string', 'set' => "VARCHAR({$limit})",
				default => throw new \LogicException("No MySQL SQL type mapping for '{$columnDefinition['type']}'"),
			};
		}

		/**
		 * PostgreSQL DDL type mapping: no UNSIGNED, no TINYINT/YEAR (SMALLINT
		 * covers both — Postgres has no 1-byte integer type); native
		 * BOOLEAN/UUID/BYTEA replace MySQL's TINYINT(1)/CHAR(36)/BLOB.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @return string
		 */
		private static function postgresSqlColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : (TypeMapper::getDefaultLimit($columnDefinition['type']) ?? 255);

			return match ($columnDefinition['type']) {
				'tinyinteger', 'smallinteger', 'year' => 'SMALLINT',
				'integer' => 'INTEGER',
				'biginteger' => 'BIGINT',
				'float' => 'REAL',
				'decimal' => sprintf('DECIMAL(%d,%d)', $columnDefinition['precision'] ?? 10, $columnDefinition['scale'] ?? 0),
				'boolean' => 'BOOLEAN',
				'date' => 'DATE',
				'datetime', 'timestamp' => 'TIMESTAMP',
				'time' => 'TIME',
				'text' => 'TEXT',
				'blob', 'binary' => 'BYTEA',
				'json' => 'JSONB',
				'uuid' => 'UUID',
				'char' => "CHAR({$limit})",
				// PostgreSQL has no inline ENUM(...) column syntax (a native enum
				// requires a separate CREATE TYPE ... AS ENUM statement, out of
				// scope here — see supportsNativeEnums()), so this always falls
				// back to the VARCHAR floor regardless of platform capability.
				'enum' => sprintf('VARCHAR(%d)', TypeMapper::enumFallbackLimit(self::enumValues($columnDefinition))),
				'string', 'set' => "VARCHAR({$limit})",
				default => throw new \LogicException("No PostgreSQL SQL type mapping for '{$columnDefinition['type']}'"),
			};
		}

		/**
		 * SQLite DDL type mapping. SQLite derives storage affinity from the type
		 * name rather than enforcing a fixed type system, so this only needs to
		 * avoid syntax it can't parse (e.g. UNSIGNED), not a distinct name per case.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @return string
		 */
		private static function sqliteSqlColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : (TypeMapper::getDefaultLimit($columnDefinition['type']) ?? 255);

			return match ($columnDefinition['type']) {
				'tinyinteger', 'smallinteger', 'integer', 'biginteger', 'year' => 'INTEGER',
				'float' => 'REAL',
				'decimal' => sprintf('NUMERIC(%d,%d)', $columnDefinition['precision'] ?? 10, $columnDefinition['scale'] ?? 0),
				'boolean' => 'BOOLEAN',
				'date' => 'DATE',
				'datetime' => 'DATETIME',
				'time' => 'TIME',
				'timestamp' => 'TIMESTAMP',
				'text', 'json', 'uuid' => 'TEXT',
				'blob', 'binary' => 'BLOB',
				'char' => "CHAR({$limit})",
				// SQLite has no native ENUM type; always the VARCHAR floor. See
				// TypeMapper::enumFallbackLimit() for why this is a 255-character
				// floor rather than an exact fit — SQLite's ALTER TABLE can't
				// widen a column later.
				'enum' => sprintf('VARCHAR(%d)', TypeMapper::enumFallbackLimit(self::enumValues($columnDefinition))),
				'string', 'set' => "VARCHAR({$limit})",
				default => throw new \LogicException("No SQLite SQL type mapping for '{$columnDefinition['type']}'"),
			};
		}

		/**
		 * SQL Server DDL type mapping. No UNSIGNED (TINYINT is already 0-255 by
		 * definition). TIMESTAMP is deliberately never emitted — in T-SQL that
		 * name means a rowversion, not a datetime — so DATETIME2 covers both
		 * 'datetime' and 'timestamp'. TEXT/IMAGE are deprecated; their
		 * MAX-length replacements are used instead.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @return string
		 */
		private static function sqlServerSqlColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : (TypeMapper::getDefaultLimit($columnDefinition['type']) ?? 255);

			return match ($columnDefinition['type']) {
				'tinyinteger' => 'TINYINT',
				'smallinteger', 'year' => 'SMALLINT',
				'integer' => 'INT',
				'biginteger' => 'BIGINT',
				'float' => 'REAL',
				'decimal' => sprintf('DECIMAL(%d,%d)', $columnDefinition['precision'] ?? 10, $columnDefinition['scale'] ?? 0),
				'boolean' => 'BIT',
				'date' => 'DATE',
				'datetime', 'timestamp' => 'DATETIME2',
				'time' => 'TIME',
				'text', 'json' => 'NVARCHAR(MAX)',
				'blob', 'binary' => 'VARBINARY(MAX)',
				'uuid' => 'UNIQUEIDENTIFIER',
				'char' => "CHAR({$limit})",
				// SQL Server has no native ENUM type; always the VARCHAR floor.
				'enum' => sprintf('VARCHAR(%d)', TypeMapper::enumFallbackLimit(self::enumValues($columnDefinition))),
				'string', 'set' => "VARCHAR({$limit})",
				default => throw new \LogicException("No SQL Server SQL type mapping for '{$columnDefinition['type']}'"),
			};
		}
	}
