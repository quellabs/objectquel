<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;

	/**
	 * Executes an AstCreateTable statement: compiles it to dialect-correct
	 * CREATE TABLE DDL via DDLTypeMapper and runs it directly against the
	 * connection.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors TempTableExecutor's existing
	 * precedent of building and running DDL directly rather than through
	 * BuildSqlFromAst, which is a retrieve-pipeline expression visitor, not a
	 * top-level statement compiler.
	 */
	class CreateTableExecutor {

		/**
		 * Database connection used to execute the generated DDL
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * Renders CREATE TABLE DDL (keyword, physical temp-table name, column
		 * types and constraints) correctly for whichever engine is connected.
		 * @var DDLTypeMapper
		 */
		private DDLTypeMapper $ddlTypeMapper;

		/**
		 * Quotes table/column identifiers correctly for whichever engine is connected.
		 * @var SqlIdentifierQuoter
		 */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * CreateTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compile and execute a `create [temporary] Name (...)` statement.
		 * @param AstCreateTable $statement
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstCreateTable $statement): void {
			$sql = $this->buildSql($statement);

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			if ($this->connection->execute($sql) === null) {
				throw new QuelException(
					"Failed to create table '{$statement->getTableName()}': {$this->connection->getLastErrorMessage()}",
					'table_creation_error'
				);
			}
		}

		/**
		 * Builds the full CREATE TABLE statement for the connected engine.
		 * @param AstCreateTable $statement
		 * @return string
		 */
		private function buildSql(AstCreateTable $statement): string {
			$keyword = $statement->isTemporary()
				? $this->ddlTypeMapper->getCreateTempTableKeyword()
				: 'CREATE TABLE';

			// SQL Server has no CREATE TEMPORARY TABLE keyword — temp-ness comes
			// from a '#' prefix in the physical name instead (see DDLTypeMapper).
			$tableName = $statement->isTemporary()
				? $this->ddlTypeMapper->getTempTableName($statement->getTableName())
				: $statement->getTableName();

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
