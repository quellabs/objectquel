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
			if ($statement->isTemporary()) {
				$keyword = $this->ddlTypeMapper->getTemporaryCreateTableKeyword();
				$tableName = $this->ddlTypeMapper->getTempTableName($statement->getTableName());
			} else {
				$keyword = $this->ddlTypeMapper->getCreateTableKeyword();
				$tableName = $this->ddlTypeMapper->getTableName($statement->getTableName());
			}

			// mysql/mariadb/pgsql/sqlite all accept IF NOT EXISTS inline, right
			// after the CREATE [TEMPORARY] TABLE keyword.
			if ($statement->isIfNotExists() && !$isSqlServer) {
				$keyword .= ' IF NOT EXISTS';
			}

			// Build the SQL query
			$columnDefs = array_map(
				fn($column) => $this->ddlTypeMapper->renderColumnDefinition(
					$this->identifierQuoter->quoteIdentifier($column->getName()),
					$column->toColumnDefinitionArray(),
					$column->isNotNull(),
					$column->isPrimaryKey(),
					$column->isIdentity()
				),
				$statement->getColumns()
			);

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
				$tableNameRes = $this->escapeStringLiteral($tableName);
				
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
