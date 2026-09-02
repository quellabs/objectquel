<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;

	/**
	 * Renders engine-specific SQL DDL for session-scoped temporary tables,
	 * driven by a PlatformCapabilitiesInterface's reported facts. Unlike
	 * TypeMapper (abstract @Column type → PHP type, engine-agnostic), this
	 * maps to literal engine-specific SQL syntax.
	 *
	 * Used only by TempTableExecutor. Identifier/alias quoting lives in
	 * SqlIdentifierQuoter instead, since it's needed by every SQL statement,
	 * not just DDL — QuelToSQL depends on that class, not this one.
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
		 * Returns the physical table name for a session-scoped temporary table.
		 * SQL Server has no CREATE/DROP TEMPORARY keyword — a local temp table
		 * is identified purely by a leading '#' in its name — so callers must
		 * use this returned name everywhere the table is referenced, not just
		 * in the CREATE statement.
		 *
		 * @param string $baseName Logical temp table name, e.g. 'tmp_range_abc123'
		 * @return string The physical name to create and reference
		 */
		public function getTempTableName(string $baseName): string {
			return $this->platform->getDatabaseType() === 'sqlsrv' ? "#{$baseName}" : $baseName;
		}

		/**
		 * Returns the CREATE-statement keyword sequence, up to but not including
		 * the table name. SQL Server gets plain 'CREATE TABLE' — its temp-ness
		 * comes from the '#' prefix in getTempTableName(), not a keyword.
		 *
		 * NOTE: this method's "every engine except sqlsrv" default and
		 * getDropTempTableKeyword()'s "only mysql/mariadb" default point in
		 * opposite directions. That's intentional, not drift: CREATE TEMPORARY
		 * TABLE is accepted by every engine except SQL Server, while TEMPORARY
		 * in DROP TABLE is accepted only by MySQL/MariaDB — the two statements
		 * genuinely differ per engine, so mirroring one method's branch
		 * structure onto the other would misrepresent real SQL syntax.
		 * getDatabaseType() is a closed enumeration (see DatabaseAdapter), so
		 * there is no unrecognised value for the two to disagree on in practice.
		 * @return string
		 */
		public function getCreateTempTableKeyword(): string {
			return $this->platform->getDatabaseType() === 'sqlsrv' ? 'CREATE TABLE' : 'CREATE TEMPORARY TABLE';
		}

		/**
		 * Returns the DROP-statement keyword sequence, up to but not including
		 * the table name. Only MySQL/MariaDB accept TEMPORARY in DROP TABLE;
		 * every other engine rejects it there even though CREATE requires (or,
		 * for SQL Server, ignores) it. See the note on getCreateTempTableKeyword()
		 * about why this method's default direction differs from that one.
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
			$limit = is_int($columnDefinition['limit']) ? $columnDefinition['limit'] : (TypeMapper::getDefaultLimit($columnDefinition['type']) ?? 255);
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
		 * PostgreSQL DDL type mapping: no UNSIGNED, no TINYINT/YEAR (SMALLINT
		 * covers both — Postgres has no 1-byte integer type); native
		 * BOOLEAN/UUID/BYTEA replace MySQL's TINYINT(1)/CHAR(36)/BLOB.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getPostgresTempTableColumnType(array $columnDefinition): string {
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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}

		/**
		 * SQLite DDL type mapping. SQLite derives storage affinity from the type
		 * name rather than enforcing a fixed type system, so this only needs to
		 * avoid syntax it can't parse (e.g. UNSIGNED), not a distinct name per case.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getSqliteTempTableColumnType(array $columnDefinition): string {
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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}

		/**
		 * SQL Server DDL type mapping. No UNSIGNED (TINYINT is already 0-255 by
		 * definition). TIMESTAMP is deliberately never emitted — in T-SQL that
		 * name means a rowversion, not a datetime — so DATETIME2 covers both
		 * 'datetime' and 'timestamp'. TEXT/IMAGE are deprecated; their
		 * MAX-length replacements are used instead.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null} $columnDefinition
		 * @return string
		 */
		private function getSqlServerTempTableColumnType(array $columnDefinition): string {
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
				// 'string', 'enum', 'set', and any unrecognized type
				default => "VARCHAR({$limit})",
			};
		}
	}
