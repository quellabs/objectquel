<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;

	/**
	 * Renders engine-specific SQL DDL fragments for session-scoped temporary
	 * tables, driven by a PlatformCapabilitiesInterface's reported facts.
	 *
	 * Unlike TypeMapper (which maps abstract @Column types to PHP types and is
	 * engine-agnostic), this maps them to literal engine-specific SQL syntax —
	 * e.g. no UNSIGNED modifier outside MySQL/MariaDB, no TINYINT/YEAR on
	 * PostgreSQL or SQL Server, native BOOLEAN/UUID/BYTEA types on PostgreSQL
	 * instead of TINYINT(1)/CHAR(36)/BLOB. PlatformCapabilitiesInterface itself
	 * only reports facts (booleans, tokens, getDatabaseType()) and never builds
	 * SQL text — this class is where those facts turn into actual SQL.
	 *
	 * Used only by TempTableExecutor. Identifier/alias quoting is a separate,
	 * non-DDL concern (every SQL statement needs it, not just DDL) and lives in
	 * SqlIdentifierQuoter instead — QuelToSQL, which never emits DDL, depends
	 * on that class, not this one.
	 */
	class DDLTypeMapper {

		/**
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * DDLTypeMapper constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->platform = $platform;
		}

		/**
		 * Returns the physical table name to use for a session-scoped temporary
		 * table, given the logical base name.
		 *
		 * Every supported engine except SQL Server creates temporary tables by
		 * name exactly as given, distinguished from permanent tables only by the
		 * CREATE/DROP keyword (see getCreateTempTableKeyword()/getDropTempTableKeyword()).
		 * SQL Server has no such keyword: a "local temporary table" is identified
		 * purely by a leading '#' in its name. Callers must use the name this
		 * method returns for every subsequent reference to the table (DDL, DML,
		 * and any later SQL that reads from it) — not just the CREATE statement.
		 *
		 * @param string $baseName Logical temp table name, e.g. 'tmp_range_abc123'
		 * @return string The physical name to create and reference
		 */
		public function getTempTableName(string $baseName): string {
			return $this->platform->getDatabaseType() === 'sqlsrv' ? "#{$baseName}" : $baseName;
		}

		/**
		 * Returns the CREATE-statement keyword sequence for a session-scoped
		 * temporary table, up to but not including the table name.
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB/PostgreSQL/SQLite → 'CREATE TEMPORARY TABLE'
		 *   SQL Server                      → 'CREATE TABLE' (temp-ness comes from
		 *                                      the '#' prefix in getTempTableName())
		 *
		 * @return string
		 */
		public function getCreateTempTableKeyword(): string {
			return $this->platform->getDatabaseType() === 'sqlsrv' ? 'CREATE TABLE' : 'CREATE TEMPORARY TABLE';
		}

		/**
		 * Returns the DROP-statement keyword sequence for a session-scoped
		 * temporary table, up to but not including the table name.
		 *
		 * Only MySQL/MariaDB accept the TEMPORARY keyword in DROP TABLE; every
		 * other engine rejects it there even though it's required (or, for SQL
		 * Server, irrelevant) in CREATE.
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB                        → 'DROP TEMPORARY TABLE IF EXISTS'
		 *   PostgreSQL/SQLite/SQL Server          → 'DROP TABLE IF EXISTS'
		 *
		 * @return string
		 */
		public function getDropTempTableKeyword(): string {
			return in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'])
				? 'DROP TEMPORARY TABLE IF EXISTS'
				: 'DROP TABLE IF EXISTS';
		}

		/**
		 * Maps a declared @Column definition to a DDL type fragment valid for a
		 * CREATE TABLE column definition on the connected engine.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		public function getTempTableColumnType(array $columnDefinition): string {
			return match ($this->platform->getDatabaseType()) {
				'pgsql' => $this->getPostgresTempTableColumnType($columnDefinition),
				'sqlite' => $this->getSqliteTempTableColumnType($columnDefinition),
				'sqlsrv' => $this->getSqlServerTempTableColumnType($columnDefinition),
				default => $this->getMysqlTempTableColumnType($columnDefinition),
			};
		}

		/**
		 * MySQL/MariaDB DDL type mapping. Also the fallback for any database
		 * type value this class doesn't otherwise recognise.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getMysqlTempTableColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : 255;
			$unsigned = $columnDefinition['unsigned'] ? ' UNSIGNED' : '';

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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}

		/**
		 * PostgreSQL DDL type mapping. No UNSIGNED modifier, no TINYINT/YEAR;
		 * native BOOLEAN/UUID/BYTEA types replace MySQL's TINYINT(1)/CHAR(36)/BLOB.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getPostgresTempTableColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : 255;

			return match ($columnDefinition['type']) {
				// PostgreSQL has no 1-byte integer type; SMALLINT is the closest fit.
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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}

		/**
		 * SQLite DDL type mapping. SQLite derives storage affinity from the type
		 * name rather than enforcing a fixed type system, so this only needs to
		 * avoid syntax SQLite doesn't parse (e.g. UNSIGNED) — it does not need a
		 * distinct type name per case the way the other engines do.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getSqliteTempTableColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : 255;

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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}

		/**
		 * SQL Server DDL type mapping. No UNSIGNED modifier; TINYINT is already
		 * unsigned (0-255) by definition. TIMESTAMP is deliberately avoided even
		 * for the ORM's 'timestamp' type — in T-SQL, TIMESTAMP names a rowversion
		 * type, not a datetime, so DATETIME2 is used for both 'datetime' and
		 * 'timestamp'. TEXT/BLOB use their MAX-length replacements, since TEXT/
		 * IMAGE are deprecated.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getSqlServerTempTableColumnType(array $columnDefinition): string {
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : 255;

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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}
	}
