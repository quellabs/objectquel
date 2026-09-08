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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
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
	 *
	 * Every compile path below takes a nullable EntityMetadataRecord instead
	 * of being duplicated per target kind: non-null means a property maps to
	 * a column via metadata and the property-exists/required-column/
	 * value-type checks apply; null (plain-table range, see
	 * objectquel-plain-table-range-plan.md) means the property name IS the
	 * column name and none of those checks run.
	 */
	class QuelToSQLAppend {

		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLUpsert $upsertCompiler;

		/**
		 * QuelToSQLAppend constructor
		 * @param EntityManager $entityManager Needed only for insert-from-select's
		 *        nested retrieve, which is prepared through the same
		 *        normalize/validate/optimize pipeline a top-level retrieve goes
		 *        through — QueryOptimizer specifically requires an EntityManager.
		 * @param PlatformCapabilitiesInterface $platform
		 * @param QuelToSQLUpsert $upsertCompiler Handles the on-conflict
		 *        extension when an AstAppend carries one — see this class's
		 *        docblock and QuelToSQLUpsert's own.
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform, QuelToSQLUpsert $upsertCompiler) {
			$this->entityManager = $entityManager;
			$this->entityStore = $entityManager->getEntityStore();
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
				$tableName = $statement->getTableNameOrFail();

				return $statement->isInsertFromSelect()
					? $this->compileFromSelect($statement, null, $tableName, $tableName, $parameters)
					: $this->compileValues($statement, null, $tableName, $parameters);
			}

			$metadata = $this->entityStore->getMetadata($entityName);

			return $statement->isInsertFromSelect()
				? $this->compileFromSelect($statement, $metadata, $metadata->tableName, $metadata->className, $parameters)
				: $this->compileValues($statement, $metadata, $metadata->tableName, $parameters);
		}

		/**
		 * Compiles the literal-values form (single or multi-row) to
		 * `INSERT INTO table (cols) VALUES (...), (...)`, or — when an
		 * upsert on-conflict clause is present — the dialect-appropriate
		 * insert-or-update statement built around the same compiled rows.
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord|null $metadata Null for a plain-table range
		 * @param string $tableName
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileValues(AstAppend $statement, ?EntityMetadataRecord $metadata, string $tableName, array &$parameters): string {
			$rows = $statement->getRowsOrFail();
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			if ($metadata !== null) {
				AssignmentValidator::assertPropertiesExist($properties, $metadata);
				$this->assertRequiredColumnsSupplied($properties, $metadata);
			}

			$columnNames = $metadata !== null
				? array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties)
				: $properties;

			// Compiled once per row, keyed by property, so the plain INSERT
			// VALUES tuples and (for SQL Server's MERGE) the per-row USING
			// source can both be built from the same compiled expressions
			// without recompiling them.
			$compiledRows = array_map(
				fn(array $row) => $this->compileRow($row, $metadata, $parameters),
				$rows
			);

			$insertSql = $this->compileInsertGeneric($tableName, $columnNames, $properties, $compiledRows);
			$onConflict = $statement->getOnConflict();

			if ($onConflict === null) {
				return $insertSql;
			}

			return $this->upsertCompiler->convertToSQL($insertSql, $tableName, $metadata, $properties, $columnNames, $compiledRows, $onConflict, $parameters);
		}

		/**
		 * Compiles a single row's assignments to SQL, keyed by property. When
		 * $metadata is non-null, each value is checked against its target
		 * column's declared type first — a plain-table range has no column
		 * definitions to check against, so that step is skipped entirely.
		 * @param AstAssignment[] $row
		 * @param EntityMetadataRecord|null $metadata
		 * @param array<string, mixed> $parameters
		 * @return array<string, string> property => compiled SQL value
		 * @throws SemanticException
		 */
		private function compileRow(array $row, ?EntityMetadataRecord $metadata, array &$parameters): array {
			$compiled = [];

			foreach ($row as $assignment) {
				if ($metadata !== null) {
					$this->assertAssignmentValueTypeCompatible($assignment, $metadata);
				}

				$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
				$compiled[$assignment->getProperty()] = $builder->visitNodeAndReturnSQL($assignment->getValue());
			}

			return $compiled;
		}

		/**
		 * Checks a single assignment's value against its target column's
		 * declared type, when the property maps to one.
		 * @param AstAssignment $assignment
		 * @param EntityMetadataRecord $metadata
		 * @return void
		 * @throws SemanticException
		 */
		private function assertAssignmentValueTypeCompatible(AstAssignment $assignment, EntityMetadataRecord $metadata): void {
			$columnName = $metadata->getColumnName($assignment->getProperty());
			$columnDef = $columnName !== null ? ($metadata->columnDefinitions[$columnName] ?? null) : null;

			if ($columnDef !== null) {
				AssignmentValidator::assertValueTypeCompatible($assignment->getProperty(), $assignment->getValue(), $columnDef);
			}
		}

		/**
		 * Compiles the plain `INSERT INTO table (cols) VALUES (...), (...)`
		 * shared by the non-upsert path and as the base every upsert dialect
		 * branch (except SQL Server's MERGE, which has no INSERT of its own)
		 * builds on.
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
				$this->identifierQuoter->quoteIdentifierList($columnNames),
				implode(', ', $valueTuples)
			);
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
		 * @param EntityMetadataRecord|null $metadata Null for a plain-table range
		 * @param string $tableName
		 * @param string $targetLabel Entity class name or table name, for error messages
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileFromSelect(AstAppend $statement, ?EntityMetadataRecord $metadata, string $tableName, string $targetLabel, array &$parameters): string {
			$properties = $statement->getColumnsOrFail();
			$source = $statement->getSourceOrFail();

			if ($metadata !== null) {
				AssignmentValidator::assertPropertiesExist($properties, $metadata);
				$this->assertRequiredColumnsSupplied($properties, $metadata);
			}

			// Visibility flags are set by prepareSource()'s optimizer pass
			// (already run by the caller), so aliases can only be read afterward.
			$selectSql = $this->finalizeSourceRetrieveSql($source, $parameters);
			$visibleAliases = $this->resolveVisibleAliases($properties, $source, $targetLabel);

			$columnNames = $metadata !== null
				? array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties)
				: $properties;

			$derivedTableAlias = $this->identifierQuoter->quoteIdentifier('__append_source');

			$reprojectedColumns = implode(', ', array_map(
				fn(string $alias) => $derivedTableAlias . '.' . $this->identifierQuoter->quoteIdentifier($alias),
				$visibleAliases
			));

			return sprintf(
				'INSERT INTO %s (%s) SELECT %s FROM (%s) AS %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->identifierQuoter->quoteIdentifierList($columnNames),
				$reprojectedColumns,
				$selectSql,
				$derivedTableAlias
			);
		}

		/**
		 * Resolves identifiers, normalizes, validates, and optimizes the
		 * insert-from-select source — the same pipeline a top-level retrieve
		 * goes through. Mutates $source in place (identifier types, range
		 * rewrites, temp-table promotion). Must run exactly once per
		 * statement (the caller, AppendExecutor::prepareInsertFromSelectSource(),
		 * owns that) — after that, needsPlanner()/finalizeSourceRetrieveSql()/
		 * resolveVisibleAliases() can all be called freely.
		 * @param AstRetrieve $source
		 * @param array<string, mixed> $parameters
		 * @return void
		 */
		public function prepareSource(AstRetrieve $source, array &$parameters): void {
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
		}

		/**
		 * Compiles an already-prepared (see prepareSource()) source retrieve to
		 * a plain SQL SELECT string, for embedding in `INSERT INTO ... SELECT ...`.
		 * Only valid when needsPlanner($source) is false — QuelToSQLRetrieve
		 * silently drops any range it doesn't understand (JSON-source ranges,
		 * temp-table-promoted subquery ranges), so this must never be called on
		 * a source that needs the planner instead (see
		 * AppendExecutor::executeInsertFromSelectViaPlanner()).
		 * @param AstRetrieve $source
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function finalizeSourceRetrieveSql(AstRetrieve $source, array &$parameters): string {
			return (new QuelToSQLRetrieve($this->entityStore, $parameters, $this->platform))->convertToSQL($source);
		}

		/**
		 * Whether a prepared (see prepareSource()) source retrieve needs the
		 * full ExecutionPlanBuilder/PlanExecutor pipeline instead of a plain
		 * inline SQL SELECT — true for a JSON-source range or a subquery
		 * range promoted to AstRangeDatabaseTempTable.
		 *
		 * No recursion: DatabaseRangePromotor has already resolved every
		 * AstRangeDatabaseSubquery at this level to either TempTable (caught
		 * below) or Materialized (provably external-source-free, safe to
		 * inline) — same non-recursive assumption
		 * ExecutionPlanBuilder::extractTemporaryRanges() makes.
		 * @param AstRetrieve $source
		 * @return bool
		 */
		public function needsPlanner(AstRetrieve $source): bool {
			if (!empty($source->getOtherRanges())) {
				return true;
			}

			foreach ($source->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseTempTable) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Returns the source retrieve's visible (showInResult() === true)
		 * projection aliases, in declaration order, positionally matching
		 * $properties — shared by the inline INSERT...SELECT path above and
		 * AppendExecutor::executeInsertFromSelectViaPlanner(), both of which
		 * need the same column<->alias mapping to reproject the source's
		 * result columns onto the append's declared column list.
		 * @param string[] $properties
		 * @param AstRetrieve $source
		 * @param string $targetLabel Table or entity class name, for the error message
		 * @return string[] Visible alias names, in order
		 * @throws SemanticException
		 */
		public function resolveVisibleAliases(array $properties, AstRetrieve $source, string $targetLabel): array {
			$visibleAliases = array_values(array_filter(array_map(
				fn(AstAlias $value) => $value->showInResult() ? $value->getName() : null,
				$source->getValues()
			)));

			if (count($visibleAliases) !== count($properties)) {
				throw new SemanticException(sprintf(
					"append to '%s': column list has %d column(s) but the source retrieve selects %d",
					$targetLabel,
					count($properties),
					count($visibleAliases)
				));
			}

			return $visibleAliases;
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
		 * Every non-nullable, non-defaulted, non-generated (primary key) column
		 * must be supplied, or the database would reject the INSERT at runtime
		 * — this catches that at compile time instead (see
		 * objectquel-append-plan.md). Entity-backed targets only — a
		 * plain-table range has no column definitions to check against.
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
