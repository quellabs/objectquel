<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;

	/**
	 * Compiles an AstCreateTable statement to dialect-correct CREATE TABLE DDL.
	 * Sibling to QuelToSQLRetrieve/QuelToSQLDestroy — each QUEL statement kind
	 * gets its own compiler here, rather than folding DDL into
	 * QuelToSQLRetrieve, which is retrieve-specific (EntityStore-driven joins,
	 * expressions) and never emits DDL.
	 */
	class QuelToSQLCreate {

		private DDLTypeMapper $ddlTypeMapper;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLCreate constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Resolves the physical table name a `create [temporary] Name (...)`
		 * statement targets — the '#'-prefixed sqlsrv temp-table name when
		 * temporary, the logical name unchanged otherwise. Exposed
		 * separately from convertToSQL() so callers assembling further DDL
		 * against the same table (e.g. CreateTableExecutor's embedded
		 * indexes) target the same physical name, not the logical one.
		 * @param AstCreateTable $statement
		 * @return string
		 */
		public function getPhysicalTableName(AstCreateTable $statement): string {
			return $statement->isTemporary()
				? $this->ddlTypeMapper->getTempTableName($statement->getTableName())
				: $this->ddlTypeMapper->getTableName($statement->getTableName());
		}

		/**
		 * Compiles a `create [temporary] Name (...) [if not exists]`
		 * statement to SQL.
		 * @param AstCreateTable $statement
		 * @return string
		 */
		public function convertToSQL(AstCreateTable $statement): string {
			// SQL Server needs special syntax
			$isSqlServer = $this->platform->getDatabaseType() === 'sqlsrv';

			// SQL Server has no CREATE TEMPORARY TABLE keyword — temp-ness comes
			// from a '#' prefix in the physical name instead (see DDLTypeMapper).
			$keyword = $statement->isTemporary()
				? $this->ddlTypeMapper->getTemporaryCreateTableKeyword()
				: $this->ddlTypeMapper->getCreateTableKeyword();
			$tableName = $this->getPhysicalTableName($statement);

			// mysql/mariadb/pgsql/sqlite all accept IF NOT EXISTS inline, right
			// after the CREATE [TEMPORARY] TABLE keyword.
			if ($statement->isIfNotExists() && !$isSqlServer) {
				$keyword .= ' IF NOT EXISTS';
			}

			// Build the SQL query. A column's effective NOT NULL-ness folds
			// in PK membership here rather than being stored back onto the
			// column — PK is a separate, possibly-out-of-order clause, not a
			// per-column flag (see objectquel-primary-key-design.md).
			$primaryKeyColumns = $statement->getPrimaryKeyColumns();

			$columnDefs = array_map(
				fn($column) => $this->ddlTypeMapper->renderColumnDefinition(
					$this->identifierQuoter->quoteIdentifier($column->getName()),
					$column->toColumnDefinitionArray(),
					$column->isNotNull() || in_array($column->getName(), $primaryKeyColumns, true),
					$column->isIdentity()
				),
				$statement->getColumns()
			);

			// SQLite's single-column identity PK is rendered inline by
			// DDLTypeMapper (INTEGER PRIMARY KEY AUTOINCREMENT) — adding a
			// trailing PRIMARY KEY constraint on top of that is invalid
			// SQLite syntax. Every other PK shape, on every dialect
			// including SQLite, gets a trailing table constraint.
			$isSqlite = $this->platform->getDatabaseType() === 'sqlite';
			$hasIdentityColumn = array_filter($statement->getColumns(), fn($column) => $column->isIdentity()) !== [];

			if ($primaryKeyColumns !== [] && !($isSqlite && $hasIdentityColumn)) {
				$quotedPkColumns = array_map(
					fn($name) => $this->identifierQuoter->quoteIdentifier($name),
					$primaryKeyColumns
				);

				$columnDefs[] = sprintf('PRIMARY KEY (%s)', implode(', ', $quotedPkColumns));
			}

			$createStatement = sprintf(
				'%s %s (%s)',
				$keyword,
				$this->identifierQuoter->quoteIdentifier($tableName),
				implode(', ', $columnDefs)
			);

			// T-SQL has no inline IF NOT EXISTS on CREATE TABLE at all (unlike
			// DROP TABLE IF EXISTS, which SQL Server does support) — the whole
			// statement is wrapped in an existence check instead, the standard
			// T-SQL workaround for this gap.
			if ($statement->isIfNotExists() && $isSqlServer) {
				$tableNameRes = $this->identifierQuoter->escapeStringLiteral($tableName);

				return sprintf(
					"IF %s IS NULL %s",
					$statement->isTemporary()
						? "OBJECT_ID('tempdb..{$tableNameRes}')"
						: "OBJECT_ID(N'{$tableNameRes}', N'U')",
					$createStatement
				);
			}

			return $createStatement;
		}
	}
