<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;

	/**
	 * Compiles an AstDestroy statement to a single `DROP TABLE <name>`
	 * statement. Sibling to QuelToSQLRetrieve/QuelToSQLCreate.
	 *
	 * `IF EXISTS` is included only when the statement's `if exists`
	 * qualifier is present; by default a missing name must fail loudly, not
	 * silently no-op — see DestroyExecutor.
	 *
	 * Dialect branching only matters for `temporary`/temp-table resolution:
	 * on mysql/mariadb/pgsql/sqlite a session-temp table's physical name is
	 * the same as its logical one (the engine resolves an unqualified name
	 * to the session's temp table first if one exists — MySQL: temp tables
	 * shadow same-named permanent ones; Postgres: the per-session temp
	 * schema is first in `search_path`; SQLite: `TEMP` is searched before
	 * `MAIN`), so plain `DROP TABLE <name>` already does the right thing
	 * whether `temporary` was written. SQL Server has no such
	 * shadowing: a local temp table's real name is `#name`, a different
	 * physical object, so `destroy Name` on SQL Server needs special
	 * handling — see convertToSQL().
	 */
	class QuelToSQLDestroy {

		private DDLTypeMapper $ddlTypeMapper;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLDestroy constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Compiles a `destroy [temporary] Name [if exists]` statement to a
		 * single `DROP TABLE` statement.
		 * @param AstDestroy $statement
		 * @return list<string>
		 */
		public function convertToSQL(AstDestroy $statement): array {
			$temporary = $statement->isTemporary();
			$isSqlServer = $this->platform->getDatabaseType() === 'sqlsrv';
			$ifExists = $statement->isIfExists();
			$name = $statement->getName();

			if ($temporary) {
				// `temporary` resolves directly to the physical name and drops it,
				// matching the non-sqlsrv case below (except SQL Server).
				return [$this->plainDrop($this->ddlTypeMapper->getTempTableName($name), $ifExists)];
			} elseif ($isSqlServer) {
				return [$this->sqlServerUnqualifiedDrop($name, $ifExists)];
			} else {
				return [$this->plainDrop($name, $ifExists)];
			}
		}

		/**
		 * A single `DROP TABLE [IF EXISTS] <name>` statement — correct
		 * whenever the physical name to drop is already known unambiguously
		 * (a permanent table anywhere, or a temp table once resolved via
		 * DDLTypeMapper::getTempTableName()).
		 * @param string $physicalName
		 * @param bool $ifExists
		 * @return string
		 */
		private function plainDrop(string $physicalName, bool $ifExists): string {
			if ($ifExists) {
				return 'DROP TABLE IF EXISTS ' . $this->identifierQuoter->quoteIdentifier($physicalName);
			} else {
				return 'DROP TABLE ' . $this->identifierQuoter->quoteIdentifier($physicalName);
			}
		}

		/**
		 * An unqualified `destroy Name` on SQL Server, where it's not known
		 * whether $name refers to a permanent table or a session-temp one.
		 * Emulates the "temp shadows permanent" priority the other three
		 * engines give unqualified names natively: drop the local temp table
		 * `#name` if a session-scoped one currently exists (checked via
		 * `tempdb..#name`, since local temp tables live in tempdb) —
		 * unconditionally, since existence was just confirmed — otherwise
		 * fall back to dropping the permanent table.
		 * @param string $name
		 * @param bool $ifExists
		 * @return string
		 */
		private function sqlServerUnqualifiedDrop(string $name, bool $ifExists): string {
			$candidateTempTableName = $this->ddlTypeMapper->getTempTableName($name);
			$quotedCandidateTempTableName = $this->identifierQuoter->quoteIdentifier($candidateTempTableName);
			$permanentDrop = $this->plainDrop($name, $ifExists);

			return "IF OBJECT_ID('tempdb..{$this->escapeStringLiteral($candidateTempTableName)}') IS NOT NULL "
				. "DROP TABLE {$quotedCandidateTempTableName} ELSE {$permanentDrop}";
		}

		/**
		 * Escapes a value for inclusion in a single-quoted T-SQL string
		 * literal (doubling embedded quotes, the ANSI SQL escaping rule).
		 * @param string $value
		 * @return string
		 */
		private function escapeStringLiteral(string $value): string {
			return str_replace("'", "''", $value);
		}
	}
