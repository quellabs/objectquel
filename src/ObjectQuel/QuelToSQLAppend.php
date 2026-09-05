<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AssignmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CoerceDateTimeParameters;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveIdentifierRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRootIdentifierType;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;

	/**
	 * Compiles an AstAppend statement to dialect-correct INSERT SQL. Sibling
	 * to QuelToSQLRetrieve/QuelToSQLCreate/QuelToSQLDestroy — each QUEL
	 * statement kind gets its own compiler here.
	 *
	 * Unlike QuelToSQLCreate/QuelToSQLDestroy, this needs EntityStore: an
	 * append's assignments are entity property names, not raw column names,
	 * and the compiler is also where the plan's scope-cut checks live — an
	 * unknown property, a missing non-nullable/non-defaulted/non-generated
	 * column, or a statically-incompatible literal value all raise a
	 * SemanticException here, at compile time, rather than letting the
	 * database reject the statement at runtime (see
	 * objectquel-append-plan.md). Value expressions (literals, parameters,
	 * casts, arithmetic) are rendered via BuildSqlFromAst, the same
	 * expression-to-SQL visitor the retrieve pipeline uses for WHERE/VALUES.
	 *
	 * When the literal-values form carries an upsert's `or replace (...)
	 * where ...` on-conflict clause (see objectquel-upsert-plan.md),
	 * compiling it is delegated to QuelToSQLUpsert once the base INSERT and
	 * per-row values are ready — there's no separate `AstUpsert` node (an
	 * upsert *is* an AstAppend with an optional AstReplace slot), so
	 * QuelToSQLUpsert isn't a sibling compiler for its own node the way
	 * QuelToSQLReplace/QuelToSQLDelete are; it exists purely to keep that
	 * dialect-branching logic out of this file.
	 */
	class QuelToSQLAppend {

		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLUpsert $upsertCompiler;

		/**
		 * QuelToSQLAppend constructor
		 * @param EntityStore $entityStore
		 * @param EntityManager $entityManager Needed only for insert-from-select's
		 *        nested retrieve, which is prepared through the same
		 *        normalize/validate/optimize pipeline a top-level retrieve goes
		 *        through — QueryOptimizer specifically requires an EntityManager.
		 * @param PlatformCapabilitiesInterface $platform
		 * @param QuelToSQLUpsert $upsertCompiler Handles the on-conflict
		 *        extension when an AstAppend carries one — see this class's
		 *        docblock and QuelToSQLUpsert's own.
		 */
		public function __construct(EntityStore $entityStore, EntityManager $entityManager, PlatformCapabilitiesInterface $platform, QuelToSQLUpsert $upsertCompiler) {
			$this->entityStore = $entityStore;
			$this->entityManager = $entityManager;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->upsertCompiler = $upsertCompiler;
		}

		/**
		 * Compiles an `append to <range> (...)` statement to SQL.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 *        (mutated only for insert-from-select's nested retrieve)
		 * @return string
		 * @throws SemanticException
		 */
		public function convertToSQL(AstAppend $statement, array &$parameters): string {
			$entityName = $statement->getEntityName();

			if ($entityName === null) {
				return $this->convertTableAppendToSQL($statement, $parameters);
			}

			$metadata = $this->entityStore->getMetadata($entityName);

			if ($statement->isInsertFromSelect()) {
				return $this->compileInsertFromSelect($statement, $metadata, $parameters);
			}

			return $this->compileInsertValues($statement, $metadata, $parameters);
		}

		/**
		 * Compiles an `append to <range> (...)` statement targeting a
		 * plain-table range (no entity metadata) — see
		 * objectquel-plain-table-range-plan.md. Property names are used
		 * literally as column names, with none of compileInsertValues()'s
		 * metadata-driven checks (property-exists, required-column,
		 * value-type compatibility): there is no metadata to check against,
		 * so an invalid column or a missing required one surfaces as the
		 * database's own error at execution time instead. An upsert's
		 * `or replace (...) where ...` on-conflict clause is supported here
		 * too — delegated to QuelToSQLUpsert with a null EntityMetadataRecord,
		 * which skips the declared-constraint check the entity path runs
		 * (see QuelToSQLUpsert::convertToSQL()'s docblock).
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function convertTableAppendToSQL(AstAppend $statement, array &$parameters): string {
			$tableName = $statement->getTableNameOrFail();

			if ($statement->isInsertFromSelect()) {
				return $this->compileTableInsertFromSelect($statement, $tableName, $parameters);
			}

			return $this->compileTableInsertValues($statement, $tableName, $parameters);
		}

		/**
		 * Compiles the literal-values form (single or multi-row) for a
		 * plain-table range to `INSERT INTO table (cols) VALUES (...), (...)`.
		 * @param AstAppend $statement
		 * @param string $tableName
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function compileTableInsertValues(AstAppend $statement, string $tableName, array &$parameters): string {
			$rows = $statement->getRowsOrFail();
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			$compiledRows = array_map(
				fn(array $row) => $this->compileTableRow($row, $parameters),
				$rows
			);

			// A plain-table range has no metadata, so column names are just the
			// property names themselves (property IS column here).
			$insertSql = $this->compileInsertGeneric($tableName, $properties, $properties, $compiledRows);
			$onConflict = $statement->getOnConflict();

			if ($onConflict === null) {
				return $insertSql;
			}

			return $this->upsertCompiler->convertToSQL($insertSql, $tableName, null, $properties, $properties, $compiledRows, $onConflict, $parameters);
		}

		/**
		 * Compiles a single row's assignments to SQL, keyed by property, for a
		 * plain-table append — no column-type check, since there's no column
		 * definition to check it against.
		 * @param AstAssignment[] $row
		 * @param array<string, mixed> $parameters
		 * @return array<string, string> property => compiled SQL value
		 */
		private function compileTableRow(array $row, array &$parameters): array {
			$compiled = [];

			foreach ($row as $assignment) {
				$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
				$compiled[$assignment->getProperty()] = $builder->visitNodeAndReturnSQL($assignment->getValue());
			}

			return $compiled;
		}

		/**
		 * Compiles the insert-from-select form for a plain-table range to
		 * `INSERT INTO table (cols) SELECT ...` — see compileInsertFromSelect()
		 * for why the inner SELECT is wrapped as a derived table.
		 * @param AstAppend $statement
		 * @param string $tableName
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileTableInsertFromSelect(AstAppend $statement, string $tableName, array &$parameters): string {
			$properties = $statement->getColumnsOrFail();
			$source = $statement->getSourceOrFail();

			$selectSql = $this->compileSourceRetrieve($source, $parameters);

			$visibleAliases = array_values(array_filter(array_map(
				fn(AstAlias $value) => $value->showInResult() ? $value->getName() : null,
				$source->getValues()
			)));

			if (count($visibleAliases) !== count($properties)) {
				throw new SemanticException(sprintf(
					"append to '%s': column list has %d column(s) but the source retrieve selects %d",
					$tableName,
					count($properties),
					count($visibleAliases)
				));
			}

			$derivedTableAlias = $this->identifierQuoter->quoteIdentifier('__append_source');

			$reprojectedColumns = implode(', ', array_map(
				fn(string $alias) => $derivedTableAlias . '.' . $this->identifierQuoter->quoteIdentifier($alias),
				$visibleAliases
			));

			return sprintf(
				'INSERT INTO %s (%s) SELECT %s FROM (%s) AS %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->quoteIdentifierList($properties),
				$reprojectedColumns,
				$selectSql,
				$derivedTableAlias
			);
		}

		/**
		 * Shared `INSERT INTO table (cols) VALUES (...), (...)` assembly for
		 * the plain-table literal-values path — the metadata-driven
		 * compileInsert() above stays entity-only since it takes an
		 * EntityMetadataRecord for its table name.
		 * @param string $tableName
		 * @param string[] $columnNames
		 * @param string[] $properties Row property order — determines column order
		 * @param array<int, array<string, string>> $compiledRows
		 * @return string
		 */
		private function compileInsertGeneric(string $tableName, array $columnNames, array $properties, array $compiledRows): string {
			$valueTuples = array_map(
				fn(array $compiledRow) => '(' . implode(', ', array_map(fn(string $property) => $compiledRow[$property], $properties)) . ')',
				$compiledRows
			);

			return sprintf(
				'INSERT INTO %s (%s) VALUES %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->quoteIdentifierList($columnNames),
				implode(', ', $valueTuples)
			);
		}

		/**
		 * Compiles the literal-values form (single or multi-row) to
		 * `INSERT INTO table (cols) VALUES (...), (...)`, or — when an
		 * upsert on-conflict clause is present — the dialect-appropriate
		 * insert-or-update statement built around the same compiled rows.
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileInsertValues(AstAppend $statement, EntityMetadataRecord $metadata, array &$parameters): string {
			$rows = $statement->getRowsOrFail();
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			AssignmentValidator::assertPropertiesExist($properties, $metadata);
			$this->assertRequiredColumnsSupplied($properties, $metadata);

			$columnNames = array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties);

			// Compiled once per row, keyed by property, so the plain INSERT
			// VALUES tuples and (for SQL Server's MERGE) the per-row USING
			// source can both be built from the same compiled expressions
			// without recompiling them.
			$compiledRows = array_map(
				fn(array $row) => $this->compileRow($row, $metadata, $parameters),
				$rows
			);

			$insertSql = $this->compileInsert($metadata, $columnNames, $properties, $compiledRows);
			$onConflict = $statement->getOnConflict();

			if ($onConflict === null) {
				return $insertSql;
			}

			return $this->upsertCompiler->convertToSQL($insertSql, $metadata->tableName, $metadata, $properties, $columnNames, $compiledRows, $onConflict, $parameters);
		}

		/**
		 * Compiles a single row's assignments to SQL, keyed by property, after
		 * checking each value against its target column's declared type.
		 * @param AstAssignment[] $row
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return array<string, string> property => compiled SQL value
		 * @throws SemanticException
		 */
		private function compileRow(array $row, EntityMetadataRecord $metadata, array &$parameters): array {
			$compiled = [];

			foreach ($row as $assignment) {
				$compiled[$assignment->getProperty()] = $this->compileAssignmentValue($assignment, $metadata, $parameters);
			}

			return $compiled;
		}

		/**
		 * Compiles a single assignment's value expression to a SQL fragment,
		 * after checking it against the target column's declared type.
		 * @param AstAssignment $assignment
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileAssignmentValue(AstAssignment $assignment, EntityMetadataRecord $metadata, array &$parameters): string {
			$columnName = $metadata->getColumnName($assignment->getProperty());
			$columnDef = $columnName !== null ? ($metadata->columnDefinitions[$columnName] ?? null) : null;

			if ($columnDef !== null) {
				AssignmentValidator::assertValueTypeCompatible($assignment->getProperty(), $assignment->getValue(), $columnDef);
			}

			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
			return $builder->visitNodeAndReturnSQL($assignment->getValue());
		}

		/**
		 * Compiles the plain `INSERT INTO table (cols) VALUES (...), (...)`
		 * shared by the non-upsert path and as the base every upsert dialect
		 * branch (except SQL Server's MERGE, which has no INSERT of its own)
		 * builds on.
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $columnNames
		 * @param string[] $properties Row property order — determines column order
		 * @param array<int, array<string, string>> $compiledRows
		 * @return string
		 */
		private function compileInsert(EntityMetadataRecord $metadata, array $columnNames, array $properties, array $compiledRows): string {
			return $this->compileInsertGeneric($metadata->tableName, $columnNames, $properties, $compiledRows);
		}

		/**
		 * @param string[] $identifiers Unquoted identifiers
		 * @return string Comma-separated, quoted identifier list
		 */
		private function quoteIdentifierList(array $identifiers): string {
			return implode(', ', array_map(
				fn(string $identifier) => $this->identifierQuoter->quoteIdentifier($identifier),
				$identifiers
			));
		}

		/**
		 * Compiles the insert-from-select form to
		 * `INSERT INTO table (cols) SELECT ...`.
		 *
		 * The nested retrieve's optimizer pass may append hidden projections
		 * of its own (e.g. JoinConditionFieldInjector adding a field a WHERE
		 * condition needs but the user didn't request — see
		 * Planner\Optimizers\JoinConditionFieldInjector) — those would break a
		 * direct `INSERT INTO (cols) SELECT ...` column-count match, so the
		 * compiled inner SELECT is wrapped as a derived table and only its
		 * originally-requested (showInResult() === true) columns are
		 * re-projected in the outer SELECT.
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileInsertFromSelect(AstAppend $statement, EntityMetadataRecord $metadata, array &$parameters): string {
			$properties = $statement->getColumnsOrFail();
			$source = $statement->getSourceOrFail();

			AssignmentValidator::assertPropertiesExist($properties, $metadata);
			$this->assertRequiredColumnsSupplied($properties, $metadata);

			// Visibility flags are only set by the optimizer pass inside
			// compileSourceRetrieve(), so the requested-columns list can only
			// be read off $source afterward.
			$selectSql = $this->compileSourceRetrieve($source, $parameters);

			$visibleAliases = array_values(array_filter(array_map(
				fn(AstAlias $value) => $value->showInResult() ? $value->getName() : null,
				$source->getValues()
			)));

			if (count($visibleAliases) !== count($properties)) {
				throw new SemanticException(sprintf(
					"append to '%s': column list has %d column(s) but the source retrieve selects %d",
					$metadata->className,
					count($properties),
					count($visibleAliases)
				));
			}

			$derivedTableAlias = $this->identifierQuoter->quoteIdentifier('__append_source');

			$reprojectedColumns = implode(', ', array_map(
				fn(string $alias) => $derivedTableAlias . '.' . $this->identifierQuoter->quoteIdentifier($alias),
				$visibleAliases
			));

			return sprintf(
				'INSERT INTO %s (%s) SELECT %s FROM (%s) AS %s',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$this->quoteColumnList($properties, $metadata),
				$reprojectedColumns,
				$selectSql,
				$derivedTableAlias
			);
		}

		/**
		 * Prepares and compiles the nested `retrieve` of an insert-from-select
		 * append, through the same normalize/validate/optimize pipeline
		 * QueryExecutor runs for a top-level retrieve before handing it to
		 * QuelToSQLRetrieve.
		 * @param AstRetrieve $source
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function compileSourceRetrieve(AstRetrieve $source, array &$parameters): string {
			foreach ($source->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseSubquery) {
					$this->resolveIdentifierTypes($range->getQuery());
				}
			}

			$this->resolveIdentifierTypes($source);

			(new QueryNormalizer($this->entityStore, $this->entityManager->getConnection()))->transform($source);
			$source->accept(new CoerceDateTimeParameters($parameters));
			(new SemanticAnalyzer($this->entityStore, $this->platform, $this->entityManager->getConnection()))->validate($source);
			(new QueryOptimizer($this->entityManager, $this->platform))->transform($source, $parameters);

			return (new QuelToSQLRetrieve($this->entityStore, $parameters, $this->platform))->convertToSQL($source);
		}

		/**
		 * Resolves identifier types on a retrieve, mirroring
		 * QueryExecutor::resolveAndSetIdentifierTypes().
		 * @param AstRetrieve $retrieve
		 * @return void
		 */
		private function resolveIdentifierTypes(AstRetrieve $retrieve): void {
			$retrieve->accept(new ResolveRootIdentifierType($retrieve));
			$retrieve->accept(new ResolvePropertyType($this->entityStore));
			$retrieve->accept(new ResolveIdentifierRange($retrieve));
		}

		/**
		 * @param string[] $properties
		 * @param EntityMetadataRecord $metadata
		 * @return string Comma-separated, quoted column list
		 */
		private function quoteColumnList(array $properties, EntityMetadataRecord $metadata): string {
			return $this->quoteIdentifierList(array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties));
		}

		/**
		 * Every non-nullable, non-defaulted, non-generated (primary key) column
		 * must be supplied, or the database would reject the INSERT at runtime
		 * — this catches that at compile time instead (see
		 * objectquel-append-plan.md).
		 * @param string[] $properties
		 * @param EntityMetadataRecord $metadata
		 * @return void
		 * @throws SemanticException
		 */
		private function assertRequiredColumnsSupplied(array $properties, EntityMetadataRecord $metadata): void {
			$supplied = array_flip($properties);
			$missing = [];

			foreach ($metadata->columnDefinitions as $columnName => $columnDef) {
				if ($columnDef['nullable'] || $columnDef['primary_key'] || !empty($columnDef['default'])) {
					continue;
				}

				$property = $metadata->getPropertyName($columnName);

				if ($property === null || isset($supplied[$property])) {
					continue;
				}

				$missing[] = $property;
			}

			if (!empty($missing)) {
				throw new SemanticException(sprintf(
					"append to '%s' is missing required propert%s: %s",
					$metadata->className,
					count($missing) === 1 ? 'y' : 'ies',
					implode(', ', $missing)
				));
			}
		}
	}
