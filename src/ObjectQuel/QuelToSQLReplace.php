<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AliasedDmlSql;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AssignmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\DateTimeWriteSql;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SetTargetColumnQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbParameterNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CoerceDateTimeParameters;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NormalizeDateTime;
	use Quellabs\ObjectQuel\Persistence\VersionValueHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\SQLSerializer;

	/**
	 * Compiles an AstReplace statement to dialect-correct UPDATE SQL. Sibling
	 * to QuelToSQLAppend/QuelToSQLCreate/QuelToSQLDestroy.
	 *
	 * The target table is aliased in the generated UPDATE, but the SET
	 * target column can't always be qualified with it: PostgreSQL/SQLite
	 * reject an alias-qualified column on SET's left side, so it stays bare
	 * there while MySQL/MariaDB/SQL Server qualify it like anywhere else.
	 * SET values and WHERE reuse BuildSqlFromAst, alias-qualified, same as
	 * `retrieve`.
	 *
	 * buildSetClause() is also reused by QuelToSQLUpsert for an on-conflict
	 * UPDATE that has no table alias in scope, always getting the bare form.
	 *
	 * Like `delete`, `replace`'s WHERE only ever has one range to resolve
	 * against, so it skips the QueryNormalizer/SemanticAnalyzer/
	 * QueryOptimizer machinery `retrieve` needs.
	 */
	class QuelToSQLReplace {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private VersionValueHandler $versionValueHandler;
		private SQLSerializer $serializer;
		private ResolveType $valueTypes;

		/**
		 * QuelToSQLReplace constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 * @param VersionValueHandler $versionValueHandler Reused as-is (not
		 *        reconstructed) so `replace` bumps @Orm\Version columns using
		 *        the exact same logic persist()'s UPDATE path does.
		 * @param ResolveType|null $valueTypes Types assigned values; defaults to one that knows entity columns only
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, VersionValueHandler $versionValueHandler, ?ResolveType $valueTypes = null) {
			$this->entityStore = $entityStore;
			$this->valueTypes = $valueTypes ?? new ResolveType($entityStore);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->versionValueHandler = $versionValueHandler;
			// Only needs EntityStore (see Serializer's constructor) — built
			// here rather than threaded in from EntityManager, so
			// WriteVerbParameterNormalizer denormalizes bound-parameter
			// values exactly like InsertPersister/append do.
			$this->serializer = new SQLSerializer($entityStore);
		}

		/**
		 * Compiles a `replace <range> (...) where ...` statement to SQL.
		 * @param AstReplace $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException|QuelException|EntityResolutionException
		 */
		public function convertToSQL(AstReplace $statement, array &$parameters): string {
			// Identifiers in the WHERE clause and assignment values (e.g.
			// `count = count + 1`) need a resolved type/range before anything
			// below can compile them to SQL.
			WriteVerbIdentifierResolver::resolve($statement, $this->entityStore);

			$range = $statement->getRange();
			$metadata = $this->entityStore->getMetadata($range->getEntityName());

			// Normalize bound-parameter values before compiling them to SQL —
			// see WriteVerbParameterNormalizer's docblock. A `replace`
			// bypasses UnitOfWork entirely (see this class's docblock), so
			// without this a raw PHP value (a \DateTime object, a json
			// column's array, a backed enum) would reach the driver
			// unconverted — both for the SET clause's assignment values and
			// for a WHERE-clause comparison against a real entity column
			// (e.g. `where u.deletedAt > :since`). One shared instance
			// normalizes both halves so a parameter reused between them
			// isn't denormalized twice.
			$conditions = $statement->getConditionsOrFail();

			// Datetime comparisons work in Unix timestamps, as in retrieve
			$conditions->accept(new NormalizeDateTime($this->entityStore));

			$normalizer = new WriteVerbParameterNormalizer($metadata, $this->serializer, $parameters);
			$normalizer->normalizeAssignments($statement->getAssignments());
			$conditions->accept($normalizer);
			$this->coerceConditionParameters($conditions, $statement->getAssignments(), $parameters);

			$setClauseParts = $this->buildSetClause($statement->getAssignments(), $metadata, $parameters, $range->getName());

			return AliasedDmlSql::update(
				$metadata->tableName,
				$range->getName(),
				implode(', ', $setClauseParts),
				$this->compileCondition($conditions, $parameters),
				$this->identifierQuoter,
				$this->platform
			);
		}

		/**
		 * Compiles a routine's current-row `replace x (...)` against the cursor's source range, bumping version columns like `replace`.
		 * On SQL Server the table is left unaliased, the documented `WHERE CURRENT OF` form.
		 * @param AstRangeDatabase $range The cursor's source range
		 * @param AstAssignment[] $assignments Column assignments
		 * @param string $rowCondition SQL condition selecting the current row, e.g. `CURRENT OF cursor`
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException
		 */
		public function convertCurrentRowToSQL(AstRangeDatabase $range, array $assignments, string $rowCondition, array &$parameters): string {
			$metadata = $this->entityStore->getMetadata($range->getEntityName());

			if (!$this->platform->supportsAliasAfterDmlTarget()) {
				$setSql = implode(', ', $this->buildSetClause($assignments, $metadata, $parameters));
				return 'UPDATE ' . $this->identifierQuoter->quoteIdentifier($metadata->tableName) . " SET {$setSql} WHERE {$rowCondition}";
			}

			$setSql = implode(', ', $this->buildSetClause($assignments, $metadata, $parameters, $range->getName()));
			return AliasedDmlSql::update($metadata->tableName, $range->getName(), $setSql, $rowCondition, $this->identifierQuoter, $this->platform);
		}

		/**
		 * Builds the `` `col` = <sql> `` SET-clause fragments for a set of
		 * assignments against $metadata's entity — property-exists/type
		 * checks, bare (unqualified) target columns (see this class's
		 * docblock for why), and an automatic bump for any @Orm\Version
		 * column not explicitly assigned. Public so QuelToSQLAppend can reuse
		 * it unchanged for upsert's `or replace (...)` on-conflict UPDATE
		 * clause — the exact same rules apply there, just folded into an
		 * INSERT instead of a standalone UPDATE statement.
		 * @param AstAssignment[] $assignments
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @param string|null $qualifyWithAlias The UPDATE's own range alias, to
		 *        qualify each target column with where the dialect allows it
		 *        (see quoteSetTargetColumn()); null to always render bare —
		 *        used by QuelToSQLUpsert's on-conflict reuse, which has no
		 *        alias in scope at all (see this class's docblock).
		 * @return string[]
		 * @throws SemanticException
		 */
		public function buildSetClause(array $assignments, EntityMetadataRecord $metadata, array &$parameters, ?string $qualifyWithAlias = null): array {
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $assignments);

			AssignmentValidator::assertPropertiesExist($properties, $metadata);

			$setClauseParts = array_map(
				fn(AstAssignment $assignment) => $this->compileAssignment($assignment, $metadata, $parameters, $qualifyWithAlias),
				$assignments
			);

			// Any @Orm\Version column the caller didn't explicitly assign
			// still bumps — replace/upsert bypass UnitOfWork entirely, so
			// without this the version column would silently go stale (see
			// objectquel-replace-plan.md).
			$versionColumnsToBump = array_diff_key($metadata->versionColumns, array_flip($properties));

			if (!empty($versionColumnsToBump)) {
				$setClauseParts = array_merge(
					$setClauseParts,
					$this->versionValueHandler->buildVersionSetClause($versionColumnsToBump, $parameters, $qualifyWithAlias)
				);
			}

			return $setClauseParts;
		}

		/**
		 * Compiles a single `property = value` assignment to a `` `col` = <sql> ``
		 * SET fragment, after checking the value against the column's declared
		 * type. The target column goes through quoteSetTargetColumn() (see this
		 * class's docblock) rather than BuildSqlFromAst/AstIdentifier. A Unix
		 * timestamp written to a datetime column is converted to a datetime.
		 * @param AstAssignment $assignment
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @param string|null $qualifyWithAlias See buildSetClause()'s docblock.
		 * @return string
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		private function compileAssignment(AstAssignment $assignment, EntityMetadataRecord $metadata, array &$parameters, ?string $qualifyWithAlias): string {
			// getColumnNameOrFail() is safe here — buildSetClause() already ran
			// AssignmentValidator::assertPropertiesExist() against the very
			// same properties before calling this method.
			$columnName = $metadata->getColumnNameOrFail($assignment->getProperty());
			$columnDef = $metadata->columnDefinitions[$columnName] ?? null;

			if ($columnDef !== null) {
				AssignmentValidator::assertValueTypeCompatible($assignment->getProperty(), $assignment->getValue(), $columnDef);
			}

			$value = $assignment->getValue();
			$value->accept(new NormalizeDateTime($this->entityStore, $this->valueTypes));

			$valueSql = DateTimeWriteSql::convert(
				$this->compileExpression($value, $parameters),
				$this->valueTypes->inferReturnType($value),
				$columnDef === null ? null : TypeMapper::phinxTypeToPhpType($columnDef['type']),
				$assignment->getProperty(),
				$this->platform
			);

			return $this->quoteSetTargetColumn($columnName, $qualifyWithAlias) . ' = ' . $valueSql;
		}

		/**
		 * Quotes a SET-clause target column, qualifying it with the UPDATE's
		 * own range alias when both a non-null $qualifyWithAlias was given and
		 * the connected engine allows a qualified column on the LEFT side of a
		 * SET assignment — PostgreSQL and SQLite reject it there (`SET
		 * alias.col = ...` is a syntax error), so those always get the bare
		 * column regardless of $qualifyWithAlias (see this class's docblock).
		 * @param string $columnName
		 * @param string|null $qualifyWithAlias
		 * @return string
		 */
		private function quoteSetTargetColumn(string $columnName, ?string $qualifyWithAlias): string {
			return SetTargetColumnQuoter::quote($columnName, $qualifyWithAlias, $this->identifierQuoter, $this->platform);
		}

		/**
		 * Renders an assignment's value expression to SQL via BuildSqlFromAst
		 * — the same expression-to-SQL visitor the retrieve pipeline uses.
		 * Compiled in 'VALUES' mode, not 'WHERE': a SET target's value is an
		 * ordinary scalar expression, never a boolean predicate (see
		 * compileCondition() for the WHERE-clause counterpart).
		 * @param AstInterface $expression
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function compileExpression(AstInterface $expression, array &$parameters): string {
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
			return $builder->visitNodeAndReturnSQL($expression);
		}

		/**
		 * Renders the WHERE clause's condition to SQL via BuildSqlFromAst,
		 * in 'WHERE' mode — same as QuelToSQLRetrieve's own WHERE clause —
		 * so a boolean-position predicate like `any(...)` compiles to a
		 * bare `EXISTS(...)` rather than the `CASE WHEN EXISTS(...) THEN 1
		 * ELSE 0 END` 'VALUES' mode produces (see ProcessAggregate::
		 * handleAny()), which PostgreSQL rejects in a WHERE clause.
		 * @param AstInterface $condition
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function compileCondition(AstInterface $condition, array &$parameters): string {
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform);
			return $builder->visitConditionAndReturnSQL($condition);
		}

		/**
		 * Converts parameters compared with a datetime in the WHERE clause to Unix timestamps.
		 * A parameter also used as a SET value can't hold both forms, so that combination is rejected.
		 * @param AstInterface $conditions WHERE clause, after NormalizeDateTime
		 * @param AstAssignment[] $assignments SET clause
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return void
		 * @throws QuelException
		 */
		public function coerceConditionParameters(AstInterface $conditions, array $assignments, array &$parameters): void {
			$setValues = [];

			foreach ($assignments as $assignment) {
				$value = $assignment->getValue();

				if ($value instanceof AstParameter && array_key_exists($value->getName(), $parameters)) {
					$setValues[$value->getName()] = $parameters[$value->getName()];
				}
			}

			$conditions->accept(new CoerceDateTimeParameters($parameters));

			foreach ($setValues as $name => $value) {
				if ($parameters[$name] !== $value) {
					throw new QuelException("Parameter ':{$name}' is both assigned and compared with a datetime, which need different forms. Bind the comparison value under a second name.", 'type_error');
				}
			}
		}
	}
