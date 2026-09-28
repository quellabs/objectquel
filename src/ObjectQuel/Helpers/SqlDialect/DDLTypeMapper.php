<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;

	/**
	 * Builds engine-specific table and column DDL using TypeMapper for SQL types.
	 */
	class DDLTypeMapper {

		/**
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * @var SqlIdentifierQuoter
		 */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * DDLTypeMapper constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->platform = $platform;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
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
		 * Returns the physical table name for a permanent table — the base
		 * name unchanged. Trivial, but exists so a caller building either kind
		 * (e.g. QuelToSQLCreate) can pick between this and getTempTableName()
		 * without special-casing the permanent branch itself.
		 * @param string $baseName Logical table name
		 * @return string
		 */
		public function getTableName(string $baseName): string {
			return $baseName;
		}

		/**
		 * Returns the CREATE-statement keyword sequence for a temporary table,
		 * up to but not including the table name. SQL Server gets plain
		 * 'CREATE TABLE' — its temp-ness comes from the '#' prefix in
		 * getTempTableName(), not a keyword.
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
		public function getTemporaryCreateTableKeyword(): string {
			return $this->platform->getDatabaseType() === 'sqlsrv' ? 'CREATE TABLE' : 'CREATE TEMPORARY TABLE';
		}

		/**
		 * Returns the CREATE-statement keyword sequence for a permanent table —
		 * always plain 'CREATE TABLE', but exists so a caller building either
		 * kind can pick between this and getTemporaryCreateTableKeyword()
		 * without hardcoding the literal itself.
		 * @return string
		 */
		public function getCreateTableKeyword(): string {
			return 'CREATE TABLE';
		}

		/**
		 * Returns the DROP-statement keyword sequence, up to but not including
		 * the table name. Only MySQL/MariaDB accept TEMPORARY in DROP TABLE;
		 * every other engine rejects it there even though CREATE requires (or,
		 * for SQL Server, ignores) it. See the note on
		 * getTemporaryCreateTableKeyword() about why this method's default
		 * direction differs from that one.
		 * @return string
		 */
		public function getDropTempTableKeyword(): string {
			return in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'])
				? 'DROP TEMPORARY TABLE IF EXISTS'
				: 'DROP TABLE IF EXISTS';
		}

		/**
		 * Renders a complete column definition — type plus the minimal
		 * constraint set QUEL's `create` supports (NOT NULL, identity) — for
		 * the connected engine. Unlike getTempTableColumnType() (type only,
		 * used by TempTableExecutor which never needs constraints), this is
		 * for `create`, where the author writes constraints explicitly.
		 *
		 * Primary key is not rendered here: it's a table-level
		 * `primary key (...)` clause (see objectquel-primary-key-design.md),
		 * appended as a trailing constraint by QuelToSQLCreate — except
		 * SQLite's single-column identity case, which still needs the
		 * historical inline `INTEGER PRIMARY KEY AUTOINCREMENT` idiom (see
		 * renderSqliteColumnDefinition()). $notNull passed in already
		 * accounts for "is this column part of the primary key" — computed
		 * by the caller, not derived here.
		 *
		 * @param string $quotedColumnName Already-quoted column identifier
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param bool $notNull
		 * @param bool $identity
		 * @return string
		 */
		public function renderColumnDefinition(
			string $quotedColumnName,
			array $columnDefinition,
			bool $notNull,
			bool $identity
		): string {
			return match ($this->platform->getDatabaseType()) {
				'pgsql' => $this->renderPostgresColumnDefinition($quotedColumnName, $columnDefinition, $notNull, $identity),
				'sqlite' => $this->renderSqliteColumnDefinition($quotedColumnName, $columnDefinition, $notNull, $identity),
				'sqlsrv' => $this->renderSqlServerColumnDefinition($quotedColumnName, $columnDefinition, $notNull, $identity),
				default => $this->renderMysqlColumnDefinition($quotedColumnName, $columnDefinition, $notNull, $identity),
			};
		}

		/**
		 * Maps a declared @Column definition to a DDL type fragment valid for a
		 * CREATE TABLE column definition on the connected engine.
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @return string
		 */
		public function getTempTableColumnType(array $columnDefinition): string {
			return TypeMapper::sqlColumnType(
				$columnDefinition,
				$this->platform->getDatabaseType(),
				$this->platform->supportsUnsignedIntegers(),
				$this->platform->supportsNativeEnums(),
				fn(string $value): string => $this->identifierQuoter->quoteStringLiteral($value)
			);
		}
		
		
		
		/**
		 * MySQL/MariaDB column definition. AUTO_INCREMENT columns must be NOT
		 * NULL and require a key — the trailing PRIMARY KEY table constraint
		 * QuelToSQLCreate appends satisfies that requirement.
		 * @param string $quotedColumnName Already-quoted column identifier
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param bool $notNull
		 * @param bool $identity
		 * @return string
		 */
		private function renderMysqlColumnDefinition(
			string $quotedColumnName,
			array $columnDefinition,
			bool $notNull,
			bool $identity
		): string {
			$type = $this->getTempTableColumnType($columnDefinition);
			$fragment = "{$quotedColumnName} {$type}";
			
			if ($notNull || $identity) {
				$fragment .= ' NOT NULL';
			}
			
			if ($identity) {
				$fragment .= ' AUTO_INCREMENT';
			}
			
			return $fragment;
		}
		
		/**
		 * PostgreSQL column definition. Identity uses the standard SQL identity
		 * clause (`GENERATED BY DEFAULT AS IDENTITY`, not `GENERATED ALWAYS`) so
		 * an explicit value can still be inserted, matching MySQL AUTO_INCREMENT
		 * semantics — a value is implicitly NOT NULL already.
		 * @param string $quotedColumnName Already-quoted column identifier
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param bool $notNull
		 * @param bool $identity
		 * @return string
		 */
		private function renderPostgresColumnDefinition(
			string $quotedColumnName,
			array $columnDefinition,
			bool $notNull,
			bool $identity
		): string {
			$type = $this->getTempTableColumnType($columnDefinition);
			$fragment = "{$quotedColumnName} {$type}";
			
			if ($identity) {
				$fragment .= ' GENERATED BY DEFAULT AS IDENTITY';
			} elseif ($notNull) {
				$fragment .= ' NOT NULL';
			}
			
			return $fragment;
		}
		
		/**
		 * SQLite column definition. Identity columns are rendered as the SQLite
		 * idiom `INTEGER PRIMARY KEY AUTOINCREMENT`, overriding the mapped type —
		 * this is the only column shape SQLite recognises as a rowid alias with
		 * autoincrementing behaviour, regardless of the abstract integer subtype
		 * declared (SQLite's INTEGER affinity covers all of them anyway). Every
		 * other PK shape — including a composite PK with no identity column —
		 * is rendered by QuelToSQLCreate as a trailing PRIMARY KEY (...)
		 * table constraint instead, same as the other three dialects.
		 * @param string $quotedColumnName Already-quoted column identifier
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param bool $notNull
		 * @param bool $identity
		 * @return string
		 */
		private function renderSqliteColumnDefinition(
			string $quotedColumnName,
			array $columnDefinition,
			bool $notNull,
			bool $identity
		): string {
			if ($identity) {
				return "{$quotedColumnName} INTEGER PRIMARY KEY AUTOINCREMENT";
			}
			
			$type = $this->getTempTableColumnType($columnDefinition);
			$fragment = "{$quotedColumnName} {$type}";
			
			if ($notNull) {
				$fragment .= ' NOT NULL';
			}
			
			return $fragment;
		}
		
		/**
		 * SQL Server column definition. IDENTITY(1,1) columns are implicitly NOT
		 * NULL.
		 * @param string $quotedColumnName Already-quoted column identifier
		 * @param array{type: string, limit: int|array<int,int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null} $columnDefinition
		 * @param bool $notNull
		 * @param bool $identity
		 * @return string
		 */
		private function renderSqlServerColumnDefinition(
			string $quotedColumnName,
			array $columnDefinition,
			bool $notNull,
			bool $identity
		): string {
			$type = $this->getTempTableColumnType($columnDefinition);
			$fragment = "{$quotedColumnName} {$type}";
			
			if ($identity) {
				$fragment .= ' IDENTITY(1,1)';
			}
			
			if ($notNull || $identity) {
				$fragment .= ' NOT NULL';
			}
			
			return $fragment;
		}
		

	}
