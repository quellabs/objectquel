<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Mapper;

	/**
	 * Maps each engine's native column type name to this ORM's abstract
	 * column type vocabulary (the same strings TypeMapper and DDLTypeMapper
	 * use — see objectquel-phinx-removal-plan.md). Pure string-in/string-out
	 * logic, no database access, used by DatabaseAdapter's per-engine
	 * getColumns() implementations.
	 *
	 * Only recognizes the fixed, narrow set of physical types this
	 * codebase's own DDLTypeMapper can ever render for the connected engine —
	 * not every type the engine itself can express. A column reaching one of
	 * these methods that this ORM's own DDL could never have created throws,
	 * matching this codebase's "no magic" convention: fail loudly rather than
	 * silently guess.
	 */
	class NativeColumnTypeMapper {

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
				'text' => 'text',
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
		 * DDLTypeMapper::getSqliteTempTableColumnType() itself ever emits —
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
	}
