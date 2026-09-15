<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\DiscriminatorInfoResolver;
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
	use Quellabs\ObjectQuel\Persistence\VersionValueHandler;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;

	/**
	 * Compiles an AstAppend statement to dialect-correct INSERT SQL. Sibling
	 * to QuelToSQLRetrieve/QuelToSQLCreate/QuelToSQLDestroy.
	 *
	 * Needs EntityStore (unlike QuelToSQLCreate/QuelToSQLDestroy): assignments
	 * are entity property names, and compile-time scope-cut checks (unknown
	 * property, missing required column, incompatible literal) raise
	 * SemanticException here. Values are rendered via BuildSqlFromAst.
	 *
	 * An upsert's `or replace (...) where ...` clause is delegated to
	 * QuelToSQLUpsert once the base INSERT is ready — there's no separate
	 * `AstUpsert` node. JSON-source targets are diverted to JsonAppendExecutor
	 * before reaching this class.
	 */
	class QuelToSQLAppend {

		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLUpsert $upsertCompiler;
		private VersionValueHandler $versionValueHandler;

		/**
		 * QuelToSQLAppend constructor
		 * @param EntityManager $entityManager Needed only for insert-from-select's
		 *        nested retrieve, prepared through the same pipeline a top-level
		 *        retrieve uses — QueryOptimizer requires an EntityManager.
		 * @param PlatformCapabilitiesInterface $platform
		 * @param QuelToSQLUpsert $upsertCompiler Handles an AstAppend's on-conflict extension
		 * @param VersionValueHandler $versionValueHandler Reused as-is so the
		 *        literal-values form initializes @Orm\Version columns using the
		 *        same logic InsertPersister's INSERT path does — see compileValues().
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform, QuelToSQLUpsert $upsertCompiler, VersionValueHandler $versionValueHandler) {
			$this->entityManager = $entityManager;
			$this->entityStore = $entityManager->getEntityStore();
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->upsertCompiler = $upsertCompiler;
			$this->versionValueHandler = $versionValueHandler;
		}

		/**
		 * Compiles an `append to <range> (...)` statement to SQL.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 *        (mutated only for insert-from-select's nested retrieve)
		 * @return CompiledAppendSql
		 * @throws SemanticException|EntityResolutionException
		 */
		public function convertToSQL(AstAppend $statement, array &$parameters): CompiledAppendSql {
			$entityName = $statement->getEntityName();

			if ($entityName === null) {
				throw new \LogicException(
					'QuelToSQLAppend::convertToSQL() called on a statement whose target range is not an entity range — ' .
					'a JSON-source range is compiled by JsonAppendExecutor instead, and no other range kind is possible'
				);
			}

			$metadata = $this->entityStore->getMetadata($entityName);

			return $statement->isInsertFromSelect()
				? CompiledAppendSql::single($this->compileFromSelect($statement, $metadata, $metadata->tableName, $metadata->className, $parameters))
				: $this->compileValues($statement, $metadata, $metadata->tableName, $parameters);
		}

		/**
		 * Compiles the literal-values form (single or multi-row) to
		 * `INSERT INTO table (cols) VALUES (...), (...)`, or — when an
		 * upsert on-conflict clause is present — the dialect-appropriate
		 * insert-or-update statement built around the same compiled rows.
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param string $tableName
		 * @param array<string, mixed> $parameters
		 * @return CompiledAppendSql
		 * @throws SemanticException|AnnotationReaderException
		 */
		private function compileValues(AstAppend $statement, EntityMetadataRecord $metadata, string $tableName, array &$parameters): CompiledAppendSql {
			$rows = $statement->getRowsOrFail();
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			// Assert the entity has columns
			AssignmentValidator::assertPropertiesExist($properties, $metadata);
			
			// Version columns are auto-initialized below, so they're never "missing" here.
			$this->assertRequiredColumnsSupplied($properties, $metadata, skipVersionColumns: true);

			// Un-assigned @Orm\Version columns get their INSERT initial value
			// (mirrors InsertPersister — see VersionValueHandler::buildVersionInsertValues()).
			$versionColumnsToInit = array_diff_key($metadata->versionColumns, array_flip($properties));
			$properties = array_merge($properties, array_keys($versionColumnsToInit));

			// Un-assigned @Orm\Column(default=...) columns get their declared
			// default value added, rather than relying on the table's own DDL
			// DEFAULT (which may not exist or may have drifted from the annotation).
			$defaultColumnsToInit = $this->collectDefaultColumnsToInit($properties, $metadata);
			$properties = array_merge($properties, array_keys($defaultColumnsToInit));
			$columnNames = array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties);

			// STI subclass: inject the discriminator column value so `append`
			// writes the same type marker persist() would, unless already supplied.
			$discriminatorInfo = $this->resolveDiscriminatorInfo($metadata);

			if ($discriminatorInfo !== null && in_array($discriminatorInfo['column'], $columnNames, true)) {
				$discriminatorInfo = null;
			}

			if ($discriminatorInfo !== null) {
				$properties[] = $discriminatorInfo['column'];
				$columnNames[] = $discriminatorInfo['column'];
			}

			// Compiled once per row, keyed by property, so the plain INSERT
			// VALUES tuples and (for SQL Server's MERGE) the per-row USING
			// source can both be built from the same compiled expressions
			// without recompiling them.
			$compiledRows = array_map(
				fn(array $row) => $this->compileRow($row, $metadata, $parameters, $versionColumnsToInit, $discriminatorInfo, $defaultColumnsToInit),
				$rows
			);

			// Compile append query
			$insertSql = $this->compileInsertGeneric($tableName, $columnNames, $properties, $compiledRows);
			$onConflict = $statement->getOnConflict();

			if ($onConflict === null) {
				return CompiledAppendSql::single($insertSql);
			}

			// Convert query to SQL
			return $this->upsertCompiler->convertToSQL($insertSql, $tableName, $metadata, $properties, $columnNames, $compiledRows, $onConflict, $parameters);
		}
		
		/**
		 * Compiles a single row's assignments to SQL, keyed by property, after
		 * checking each value against its target column's declared type. Also
		 * adds $versionColumnsToInit and $defaultColumnsToInit — see compileValues().
		 * @param AstAssignment[] $row
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @param array<string, array{name: string, column: \Quellabs\ObjectQuel\Annotations\Orm\Column, version: \Quellabs\ObjectQuel\Annotations\Orm\Version}> $versionColumnsToInit
		 * @param array{column: non-empty-string, value: non-empty-string}|null $discriminatorInfo STI discriminator column/value to add
		 * @param array<string, mixed> $defaultColumnsToInit property => declared @Orm\Column default value to add
		 * @return array<string, string> property (or discriminator column name) => compiled SQL value
		 * @throws SemanticException|OrmException
		 */
		private function compileRow(array $row, EntityMetadataRecord $metadata, array &$parameters, array $versionColumnsToInit = [], ?array $discriminatorInfo = null, array $defaultColumnsToInit = []): array {
			$compiled = [];

			// Caller-supplied assignments: type-check then render each value to SQL.
			foreach ($row as $assignment) {
				$this->assertAssignmentValueTypeCompatible($assignment, $metadata);

				$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
				$compiled[$assignment->getProperty()] = $builder->visitNodeAndReturnSQL($assignment->getValue());
			}

			// @Orm\Version columns the caller didn't assign: initial value for this row.
			foreach ($this->versionValueHandler->buildVersionInsertValues($versionColumnsToInit) as $property => $value) {
				$compiled[$property] = (string)$value;
			}

			// @Orm\Column(default=...) columns the caller didn't assign: declared default, as a literal.
			foreach ($defaultColumnsToInit as $property => $defaultValue) {
				$compiled[$property] = $this->formatDefaultLiteral($defaultValue);
			}

			// STI subclass and not already supplied: the type-discriminator literal.
			if ($discriminatorInfo !== null) {
				$compiled[$discriminatorInfo['column']] = $this->identifierQuoter->quoteStringLiteral($discriminatorInfo['value']);
			}

			return $compiled;
		}

		/**
		 * Finds every mapped column with a declared @Orm\Column default not
		 * already in $properties (supplied, or queued as a version column).
		 * @param string[] $properties Already-resolved property names for this statement
		 * @param EntityMetadataRecord $metadata
		 * @return array<string, mixed> property => declared default value
		 */
		private function collectDefaultColumnsToInit(array $properties, EntityMetadataRecord $metadata): array {
			$supplied = array_flip($properties);
			$defaults = [];

			foreach ($metadata->columnDefinitions as $columnName => $columnDef) {
				if ($columnDef['primary_key'] || $columnDef['default'] === null) {
					continue;
				}

				$property = $metadata->getPropertyName($columnName);

				if ($property === null || isset($supplied[$property])) {
					continue;
				}

				$defaults[$property] = $columnDef['default'];
			}

			return $defaults;
		}

		/**
		 * Renders a declared @Orm\Column default (a plain scalar, never an
		 * expression) as a SQL literal.
		 * @param mixed $value
		 * @return string
		 * @throws \LogicException If the declared default isn't a plain scalar
		 */
		private function formatDefaultLiteral(mixed $value): string {
			if (is_bool($value)) {
				return $value ? '1' : '0';
			}

			if (is_int($value) || is_float($value)) {
				return (string)$value;
			}

			if (is_string($value)) {
				return $this->identifierQuoter->quoteStringLiteral($value);
			}

			throw new \LogicException(sprintf(
				'@Orm\Column default value must be a string, int, float, or bool, got %s',
				get_debug_type($value)
			));
		}

		/**
		 * Resolves the STI discriminator column/value via DiscriminatorInfoResolver,
		 * shared with InsertPersister and Planner\Helpers\InjectDiscriminatorCondition
		 * so `append` and persist() agree. Null when the class isn't an STI subclass.
		 * @param EntityMetadataRecord $metadata
		 * @return array{column: non-empty-string, value: non-empty-string}|null
		 * @throws AnnotationReaderException If annotation metadata cannot be read
		 * @throws SemanticException When STI annotations are present but incomplete or empty
		 */
		private function resolveDiscriminatorInfo(EntityMetadataRecord $metadata): ?array {
			try {
				return DiscriminatorInfoResolver::resolve($this->entityStore, $metadata->className);
			} catch (QuelException $e) {
				throw new SemanticException($e->getMessage(), $e->getCode(), $e);
			}
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
		 * The nested retrieve's optimizer pass may append hidden projections of
		 * its own (e.g. JoinConditionFieldInjector adding a field a WHERE
		 * condition needs but the user didn't request), which would break a
		 * direct column-count match — so the inner SELECT is wrapped as a
		 * derived table and only its originally-requested
		 * (showInResult() === true) columns are re-projected outward.
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param string $tableName
		 * @param string $targetLabel Entity class name, for error messages
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 * @throws AnnotationReaderException
		 */
		private function compileFromSelect(AstAppend $statement, EntityMetadataRecord $metadata, string $tableName, string $targetLabel, array &$parameters): string {
			$properties = $statement->getColumnsOrFail();
			$source = $statement->getSourceOrFail();

			AssignmentValidator::assertPropertiesExist($properties, $metadata);
			$this->assertRequiredColumnsSupplied($properties, $metadata);

			// Visibility flags are set by prepareSource()'s optimizer pass
			// (already run by the caller), so aliases can only be read afterward.
			$selectSql = $this->finalizeSourceRetrieveSql($source, $parameters);
			$visibleAliases = $this->resolveVisibleAliases($properties, $source, $targetLabel);

			$columnNames = array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $properties);

			$derivedTableAlias = $this->identifierQuoter->quoteIdentifier('__append_source');

			$selectColumns = array_map(
				fn(string $alias) => $derivedTableAlias . '.' . $this->identifierQuoter->quoteIdentifier($alias),
				$visibleAliases
			);

			// STI subclass: inject the discriminator column as a literal
			// SELECT expression, same rule compileValues() applies to its
			// VALUES rows — there's no source column to read it from.
			$discriminatorInfo = $this->resolveDiscriminatorInfo($metadata);

			if ($discriminatorInfo !== null && !in_array($discriminatorInfo['column'], $columnNames, true)) {
				$columnNames[] = $discriminatorInfo['column'];
				$selectColumns[] = $this->identifierQuoter->quoteStringLiteral($discriminatorInfo['value']);
			}

			return sprintf(
				'INSERT INTO %s (%s) SELECT %s FROM (%s) AS %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->identifierQuoter->quoteIdentifierList($columnNames),
				implode(', ', $selectColumns),
				$selectSql,
				$derivedTableAlias
			);
		}

		/**
		 * Resolves identifiers, normalizes, validates, and optimizes the
		 * insert-from-select source — the same pipeline a top-level retrieve
		 * goes through. Mutates $source in place. Must run exactly once per
		 * statement (owned by the caller, AppendExecutor::prepareInsertFromSelectSource())
		 * — after that, needsPlanner()/finalizeSourceRetrieveSql()/resolveVisibleAliases()
		 * can all be called freely.
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

			(new QueryNormalizer($this->entityStore))->transform($source);
			$source->accept(new CoerceDateTimeParameters($parameters));
			(new SemanticAnalyzer($this->entityStore, $this->platform))->validate($source);
			(new QueryOptimizer($this->entityManager, $this->platform))->transform($source, $parameters);
		}

		/**
		 * Compiles an already-prepared (see prepareSource()) source retrieve to
		 * a plain SQL SELECT string, for embedding in `INSERT INTO ... SELECT ...`.
		 * Only valid when needsPlanner($source) is false — QuelToSQLRetrieve
		 * silently drops ranges it doesn't understand (JSON-source, temp-table-
		 * promoted subquery), so a source needing the planner must go through
		 * AppendExecutor::executeInsertFromSelectViaPlanner() instead.
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
		 * No recursion needed: DatabaseRangePromotor has already resolved every
		 * AstRangeDatabaseSubquery at this level to either TempTable (caught
		 * below) or Materialized (safe to inline) — same assumption
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
		 * must be supplied, or the database would reject the INSERT at
		 * runtime — this catches that at compile time instead.
		 * @param string[] $properties
		 * @param EntityMetadataRecord $metadata
		 * @param bool $skipVersionColumns Whether to also exempt @Orm\Version
		 *        columns from this check — true for the literal-values form,
		 *        which auto-initializes them (see compileValues()); false
		 *        (default) for insert-from-select, which has no per-row
		 *        initial-value step and so still requires the caller to
		 *        explicitly select a required version column.
		 * @return void
		 * @throws SemanticException
		 */
		private function assertRequiredColumnsSupplied(array $properties, EntityMetadataRecord $metadata, bool $skipVersionColumns = false): void {
			$supplied = array_flip($properties);
			$missing = [];

			foreach ($metadata->columnDefinitions as $columnName => $columnDef) {
				// A declared default of 0, '0', '', or false is still a default;
				// only null (no default, per Column::getDefault()) is required.
				if ($columnDef['nullable'] || $columnDef['primary_key'] || $columnDef['default'] !== null) {
					continue;
				}

				$property = $metadata->getPropertyName($columnName);

				if ($property === null || isset($supplied[$property])) {
					continue;
				}

				if ($skipVersionColumns && $metadata->isVersioned($property)) {
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
