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
	 */
	class QuelToSQLAppend {

		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLAppend constructor
		 * @param EntityStore $entityStore
		 * @param EntityManager $entityManager Needed only for insert-from-select's
		 *        nested retrieve, which is prepared through the same
		 *        normalize/validate/optimize pipeline a top-level retrieve goes
		 *        through — QueryOptimizer specifically requires an EntityManager.
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(EntityStore $entityStore, EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->entityStore = $entityStore;
			$this->entityManager = $entityManager;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
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
		 * `INSERT INTO table (cols) VALUES (...), (...)`.
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

			$quotedColumns = $this->quoteColumnList($properties, $metadata);
			$valueTuples = [];

			foreach ($rows as $row) {
				$values = array_map(
					fn(AstAssignment $assignment) => $this->compileAssignmentValue($assignment, $metadata, $parameters),
					$row
				);

				$valueTuples[] = '(' . implode(', ', $values) . ')';
			}

			return sprintf(
				'INSERT INTO %s (%s) VALUES %s',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$quotedColumns,
				implode(', ', $valueTuples)
			);
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
			$columnNames = array_map(fn(string $property) => $metadata->getColumnName($property), $properties);

			return implode(', ', array_map(
				fn(string $columnName) => $this->identifierQuoter->quoteIdentifier($columnName),
				$columnNames
			));
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
