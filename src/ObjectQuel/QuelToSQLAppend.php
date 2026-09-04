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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AssignmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\ConflictTargetResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
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
	 * where ...` on-conflict clause (see objectquel-upsert-plan.md), the
	 * generated SQL branches by dialect — all via
	 * PlatformCapabilitiesInterface::getDatabaseType(), the same "build
	 * engine-specific SQL text from scratch" branching QuelToSQLCreate/
	 * QuelToSQLDestroy already use, not a bespoke capability method for a
	 * one-off shape:
	 *   - Postgres/SQLite: `INSERT ... ON CONFLICT (cols) DO UPDATE SET ...`
	 *   - MySQL/MariaDB:   `INSERT ... ON DUPLICATE KEY UPDATE ...` — fires
	 *     on *any* unique-key collision on the table, not only the named
	 *     conflict columns; a real MySQL syntax gap, not something this
	 *     compiler can paper over.
	 *   - SQL Server:      `MERGE ... WHEN MATCHED THEN UPDATE ... WHEN NOT
	 *     MATCHED THEN INSERT ...`
	 */
	class QuelToSQLAppend {

		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLReplace $replaceCompiler;

		/**
		 * QuelToSQLAppend constructor
		 * @param EntityStore $entityStore
		 * @param EntityManager $entityManager Needed only for insert-from-select's
		 *        nested retrieve, which is prepared through the same
		 *        normalize/validate/optimize pipeline a top-level retrieve goes
		 *        through — QueryOptimizer specifically requires an EntityManager.
		 * @param PlatformCapabilitiesInterface $platform
		 * @param QuelToSQLReplace $replaceCompiler Reused (not reconstructed) for
		 *        upsert's on-conflict UPDATE SET clause, so it's built with the
		 *        exact same property-exists/type/@Orm\Version-bump rules a
		 *        standalone `replace` uses — see buildSetClause().
		 */
		public function __construct(EntityStore $entityStore, EntityManager $entityManager, PlatformCapabilitiesInterface $platform, QuelToSQLReplace $replaceCompiler) {
			$this->entityStore = $entityStore;
			$this->entityManager = $entityManager;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->replaceCompiler = $replaceCompiler;
		}

		/**
		 * Compiles an `append to <range/entity> (...)` statement to SQL.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 *        (mutated only for insert-from-select's nested retrieve)
		 * @return string
		 * @throws SemanticException
		 */
		public function convertToSQL(AstAppend $statement, array &$parameters): string {
			$metadata = $this->entityStore->getMetadata($statement->getEntityName());

			if ($statement->isInsertFromSelect()) {
				return $this->compileInsertFromSelect($statement, $metadata, $parameters);
			}

			return $this->compileInsertValues($statement, $metadata, $parameters);
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
			$rows = $statement->getRows();
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $rows[0]);

			AssignmentValidator::assertPropertiesExist($properties, $metadata);
			$this->assertRequiredColumnsSupplied($properties, $metadata);

			$columnNames = array_map(fn(string $property) => $metadata->getColumnName($property), $properties);

			// Compiled once per row, keyed by property, so the plain INSERT
			// VALUES tuples and (for SQL Server's MERGE) the per-row USING
			// source can both be built from the same compiled expressions
			// without recompiling them.
			$compiledRows = array_map(
				fn(array $row) => $this->compileRow($row, $metadata, $parameters),
				$rows
			);

			$onConflict = $statement->getOnConflict();

			if ($onConflict === null) {
				return $this->compileInsert($metadata, $columnNames, $properties, $compiledRows);
			}

			return $this->compileUpsert($metadata, $properties, $columnNames, $compiledRows, $onConflict, $parameters);
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
			$valueTuples = array_map(
				fn(array $compiledRow) => '(' . implode(', ', array_map(fn(string $property) => $compiledRow[$property], $properties)) . ')',
				$compiledRows
			);

			return sprintf(
				'INSERT INTO %s (%s) VALUES %s',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$this->quoteIdentifierList($columnNames),
				implode(', ', $valueTuples)
			);
		}

		/**
		 * Resolves and validates the on-conflict clause, then dispatches to
		 * the dialect-appropriate insert-or-update compiler (see this class's
		 * docblock).
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows
		 * @param AstReplace $onConflict
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileUpsert(
			EntityMetadataRecord $metadata,
			array $properties,
			array $columnNames,
			array $compiledRows,
			AstReplace $onConflict,
			array &$parameters
		): string {
			// The on-conflict clause's own WHERE/assignment identifiers need a
			// resolved type/range before ConflictTargetResolver or
			// buildSetClause can read them.
			WriteVerbIdentifierResolver::resolve($onConflict, $this->entityStore);

			$conflictProperties = ConflictTargetResolver::resolve($onConflict->getConditions(), $metadata);
			$this->assertConflictPropertiesSuppliedByRow($conflictProperties, $properties, $metadata);

			$conflictColumns = array_map(fn(string $property) => $metadata->getColumnName($property), $conflictProperties);
			$setClauseParts = $this->replaceCompiler->buildSetClause($onConflict->getAssignments(), $metadata, $parameters);
			$dialect = $this->platform->getDatabaseType();

			if (in_array($dialect, ['pgsql', 'sqlite'], true)) {
				return sprintf(
					'%s ON CONFLICT (%s) DO UPDATE SET %s',
					$this->compileInsert($metadata, $columnNames, $properties, $compiledRows),
					$this->quoteIdentifierList($conflictColumns),
					implode(', ', $setClauseParts)
				);
			}

			if (in_array($dialect, ['mysql', 'mariadb'], true)) {
				return sprintf(
					'%s ON DUPLICATE KEY UPDATE %s',
					$this->compileInsert($metadata, $columnNames, $properties, $compiledRows),
					implode(', ', $setClauseParts)
				);
			}

			// sqlsrv — no ON CONFLICT/ON DUPLICATE KEY UPDATE equivalent at all.
			return $this->compileMerge($metadata, $properties, $columnNames, $compiledRows, $conflictColumns, $setClauseParts);
		}

		/**
		 * Compiles the SQL Server `MERGE` form. The USING source is a VALUES
		 * row constructor exposing every appended column (not just the
		 * conflict columns) under the same compiled expressions the plain
		 * INSERT uses, so:
		 *   - the ON clause can compare target.col = source.col per conflict column
		 *   - WHEN NOT MATCHED's INSERT can reference source.col for every
		 *     column, instead of re-embedding per-row literals a second time
		 * WHEN MATCHED's UPDATE SET uses the on-conflict clause's own
		 * (independently compiled) assignment values, exactly as the other
		 * two dialect branches do — never source.*, since those expressions
		 * may differ from what was inserted.
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows
		 * @param string[] $conflictColumns
		 * @param string[] $setClauseParts
		 * @return string
		 */
		private function compileMerge(
			EntityMetadataRecord $metadata,
			array $properties,
			array $columnNames,
			array $compiledRows,
			array $conflictColumns,
			array $setClauseParts
		): string {
			$targetAlias = $this->identifierQuoter->quoteIdentifier('__upsert_target');
			$sourceAlias = $this->identifierQuoter->quoteIdentifier('__upsert_source');
			$quotedColumnList = $this->quoteIdentifierList($columnNames);

			$sourceRows = array_map(
				fn(array $compiledRow) => '(' . implode(', ', array_map(fn(string $property) => $compiledRow[$property], $properties)) . ')',
				$compiledRows
			);

			$onClauseParts = array_map(
				fn(string $column) => sprintf(
					'%s.%s = %s.%s',
					$targetAlias,
					$this->identifierQuoter->quoteIdentifier($column),
					$sourceAlias,
					$this->identifierQuoter->quoteIdentifier($column)
				),
				$conflictColumns
			);

			$insertValueRefs = array_map(
				fn(string $column) => $sourceAlias . '.' . $this->identifierQuoter->quoteIdentifier($column),
				$columnNames
			);

			return sprintf(
				'MERGE INTO %s AS %s USING (VALUES %s) AS %s (%s) ON %s WHEN MATCHED THEN UPDATE SET %s WHEN NOT MATCHED THEN INSERT (%s) VALUES (%s);',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$targetAlias,
				implode(', ', $sourceRows),
				$sourceAlias,
				$quotedColumnList,
				implode(' AND ', $onClauseParts),
				implode(', ', $setClauseParts),
				$quotedColumnList,
				implode(', ', $insertValueRefs)
			);
		}

		/**
		 * Every conflict-target property must also be part of the append's
		 * own row — otherwise there's nothing to compare against for
		 * detecting the conflict on that row. (All rows of a multi-row
		 * append share the same property set — enforced at parse time by
		 * Rules\Append — so checking the first row's set covers every row.)
		 * @param string[] $conflictProperties
		 * @param string[] $rowProperties
		 * @param EntityMetadataRecord $metadata
		 * @return void
		 * @throws SemanticException
		 */
		private function assertConflictPropertiesSuppliedByRow(array $conflictProperties, array $rowProperties, EntityMetadataRecord $metadata): void {
			$missing = array_diff($conflictProperties, $rowProperties);

			if (!empty($missing)) {
				throw new SemanticException(sprintf(
					"append ... or replace's conflict target (%s) must also be part of the append's own column list on '%s' — missing: %s",
					implode(', ', $conflictProperties),
					$metadata->className,
					implode(', ', $missing)
				));
			}
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
			$properties = $statement->getColumns();
			$source = $statement->getSource();

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

			(new QueryNormalizer($this->entityStore))->transform($source);
			$source->accept(new CoerceDateTimeParameters($parameters));
			(new SemanticAnalyzer($this->entityStore, $this->platform))->validate($source);
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
			return $this->quoteIdentifierList(array_map(fn(string $property) => $metadata->getColumnName($property), $properties));
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
