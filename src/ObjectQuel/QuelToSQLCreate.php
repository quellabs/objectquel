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

		/**
		 * QuelToSQLCreate constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compiles a `create [temporary] Name (...)` statement to SQL.
		 * @param AstCreateTable $statement
		 * @return string
		 */
		public function convertToSQL(AstCreateTable $statement): string {
			$keyword = $statement->isTemporary()
				? $this->ddlTypeMapper->getTemporaryCreateTableKeyword()
				: $this->ddlTypeMapper->getCreateTableKeyword();

			// SQL Server has no CREATE TEMPORARY TABLE keyword — temp-ness comes
			// from a '#' prefix in the physical name instead (see DDLTypeMapper).
			$tableName = $statement->isTemporary()
				? $this->ddlTypeMapper->getTempTableName($statement->getTableName())
				: $this->ddlTypeMapper->getTableName($statement->getTableName());

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

			return sprintf(
				'%s %s (%s)',
				$keyword,
				$this->identifierQuoter->quoteIdentifier($tableName),
				implode(', ', $columnDefs)
			);
		}
	}
