<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AssignmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
	use Quellabs\ObjectQuel\Persistence\VersionValueHandler;

	/**
	 * Compiles an AstReplace statement to dialect-correct UPDATE SQL. Sibling
	 * to QuelToSQLAppend/QuelToSQLCreate/QuelToSQLDestroy — each QUEL
	 * statement kind gets its own compiler here.
	 *
	 * The target table is never aliased with the QUEL range alias in the
	 * generated `UPDATE <table> as <alias> SET ... WHERE ...` for no reason
	 * beyond matching QuelToSQLRetrieve's own `as`-alias convention — it IS
	 * aliased, deliberately: PostgreSQL/SQLite reject a table-or-alias-
	 * qualified column on the LEFT side of a SET assignment (`SET
	 * alias.col = ...` is a syntax error there), but happily accept it on
	 * the RIGHT side and in WHERE. So SET's target column is always rendered
	 * bare, directly from the assignment's property name via metadata — it
	 * never goes through an AstIdentifier/BuildSqlFromAst at all — while
	 * everything else (SET values, WHERE) reuses BuildSqlFromAst exactly as
	 * the retrieve pipeline does, alias-qualified, which is valid everywhere
	 * once the range is aliased in the UPDATE clause.
	 *
	 * Unlike QuelToSQLAppend's insert-from-select, `replace`'s WHERE clause
	 * only ever has one range to resolve against (see AstReplace's
	 * "single target range only" scope cut), so identifier resolution here
	 * is the small, direct set of visitors — no QueryNormalizer/
	 * SemanticAnalyzer/QueryOptimizer, which exist for retrieve's JOIN/
	 * subquery/aggregate machinery that a single-range `replace` never needs.
	 */
	class QuelToSQLReplace {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private VersionValueHandler $versionValueHandler;

		/**
		 * QuelToSQLReplace constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 * @param VersionValueHandler $versionValueHandler Reused as-is (not
		 *        reconstructed) so `replace` bumps @Orm\Version columns using
		 *        the exact same logic persist()'s UPDATE path does.
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, VersionValueHandler $versionValueHandler) {
			$this->entityStore = $entityStore;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->versionValueHandler = $versionValueHandler;
		}

		/**
		 * Compiles a `replace <range> (...) where ...` statement to SQL.
		 * @param AstReplace $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException
		 */
		public function convertToSQL(AstReplace $statement, array &$parameters): string {
			// Identifiers in the WHERE clause and assignment values (e.g.
			// `count = count + 1`) need a resolved type/range before anything
			// below can compile them to SQL.
			WriteVerbIdentifierResolver::resolve($statement, $this->entityStore);

			$range = $statement->getRange();

			if ($range instanceof AstRangeTable) {
				return $this->convertTableReplaceToSQL($statement, $range, $parameters);
			}

			$metadata = $this->entityStore->getMetadata($range->getEntityName());
			$setClauseParts = $this->buildSetClause($statement->getAssignments(), $metadata, $parameters);

			return sprintf(
				'UPDATE %s as %s SET %s WHERE %s',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$this->identifierQuoter->quoteIdentifier($range->getName()),
				implode(', ', $setClauseParts),
				$this->compileExpression($statement->getConditions(), $parameters)
			);
		}

		/**
		 * Compiles a `replace <range> (...) where ...` statement targeting a
		 * plain-table range (no entity metadata) — see
		 * objectquel-plain-table-range-plan.md. Property names are used
		 * literally as column names, with no @Orm\Version bump (there's no
		 * annotation to drive one) and no value-type check (no column
		 * definition to check it against).
		 * @param AstReplace $statement
		 * @param AstRangeTable $range
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function convertTableReplaceToSQL(AstReplace $statement, AstRangeTable $range, array &$parameters): string {
			$setClauseParts = $this->buildSetClauseForTable($statement->getAssignments(), $parameters);

			return sprintf(
				'UPDATE %s as %s SET %s WHERE %s',
				$this->identifierQuoter->quoteIdentifier($range->getTableName()),
				$this->identifierQuoter->quoteIdentifier($range->getName()),
				implode(', ', $setClauseParts),
				$this->compileExpression($statement->getConditions(), $parameters)
			);
		}

		/**
		 * Builds the `` `col` = <sql> `` SET-clause fragments for a plain-table
		 * range — property names are used literally as column names, with no
		 * property-exists/type check (no column definition to check against)
		 * and no @Orm\Version bump (no annotation to drive one). Public so
		 * QuelToSQLUpsert can reuse it unchanged for upsert's `or replace (...)`
		 * on-conflict UPDATE clause when the target is a plain-table range
		 * (see objectquel-plain-table-range-plan.md).
		 * @param AstAssignment[] $assignments
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string[]
		 */
		public function buildSetClauseForTable(array $assignments, array &$parameters): array {
			return array_map(
				fn(AstAssignment $assignment) => $this->identifierQuoter->quoteIdentifier($assignment->getProperty()) . ' = ' . $this->compileExpression($assignment->getValue(), $parameters),
				$assignments
			);
		}

		/**
		 * Builds the `` `col` = <sql> `` SET-clause fragments for a set of
		 * assignments against $metadata's entity — property-exists/type
		 * checks, bare (unqualified) target columns (see this class's
		 * docblock for why), and an automatic bump for any @Orm\Version
		 * column not explicitly assigned. Public so QuelToSQLAppend can reuse
		 * it unchanged for upsert's `or replace (...)` on-conflict UPDATE
		 * clause (see objectquel-upsert-plan.md) — the exact same rules
		 * apply there, just folded into an INSERT instead of a standalone
		 * UPDATE statement.
		 * @param AstAssignment[] $assignments
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string[]
		 * @throws SemanticException
		 */
		public function buildSetClause(array $assignments, EntityMetadataRecord $metadata, array &$parameters): array {
			$properties = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $assignments);

			AssignmentValidator::assertPropertiesExist($properties, $metadata);

			$setClauseParts = array_map(
				fn(AstAssignment $assignment) => $this->compileAssignment($assignment, $metadata, $parameters),
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
					$this->versionValueHandler->buildVersionSetClause($versionColumnsToBump, $parameters)
				);
			}

			return $setClauseParts;
		}

		/**
		 * Compiles a single `property = value` assignment to a `` `col` = <sql> ``
		 * SET fragment, after checking the value against the column's declared
		 * type. The target column is always rendered bare (see this class's
		 * docblock) — deliberately not through BuildSqlFromAst/AstIdentifier.
		 * @param AstAssignment $assignment
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileAssignment(AstAssignment $assignment, EntityMetadataRecord $metadata, array &$parameters): string {
			$columnName = $metadata->getColumnName($assignment->getProperty());
			$columnDef = $columnName !== null ? ($metadata->columnDefinitions[$columnName] ?? null) : null;

			if ($columnDef !== null) {
				AssignmentValidator::assertValueTypeCompatible($assignment->getProperty(), $assignment->getValue(), $columnDef);
			}

			return $this->identifierQuoter->quoteIdentifier($columnName) . ' = ' . $this->compileExpression($assignment->getValue(), $parameters);
		}

		/**
		 * Renders an arbitrary expression (assignment value or WHERE
		 * condition) to SQL via BuildSqlFromAst — the same expression-to-SQL
		 * visitor the retrieve pipeline uses, so comparisons/AND/OR/functions/
		 * casts/arithmetic all work exactly as they do in a `retrieve`'s
		 * WHERE clause.
		 * @param AstInterface $expression
		 * @param array<string, mixed> $parameters
		 * @return string
		 */
		private function compileExpression(AstInterface $expression, array &$parameters): string {
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);
			return $builder->visitNodeAndReturnSQL($expression);
		}
	}
