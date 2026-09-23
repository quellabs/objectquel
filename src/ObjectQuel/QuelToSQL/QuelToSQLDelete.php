<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\AliasedDmlSql;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\RangeTableName;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SetTargetColumnQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbParameterNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CoerceDateTimeParameters;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NormalizeDateTime;
	use Quellabs\ObjectQuel\Serialization\Serializers\SQLSerializer;

	/**
	 * Compiles an AstDelete statement to dialect-correct SQL. Sibling to
	 * QuelToSQLReplace/QuelToSQLAppend.
	 *
	 * When the target entity carries @SoftDelete, `delete` compiles to an
	 * UPDATE that sets the soft-delete column instead of a real DELETE —
	 * mirroring InjectSoftDeleteCondition's read-side filtering with the
	 * same opt-out: the `@ignoreSoftDelete true` directive forces a real
	 * DELETE regardless of @SoftDelete, same directive name and meaning
	 * `retrieve` uses. An entity with no recognised soft-delete column type
	 * (see buildSoftDeleteSetClause()) falls back to a real DELETE too.
	 *
	 * The target table is aliased with the QUEL range name so WHERE-clause
	 * identifiers resolve via BuildSqlFromAst same as `retrieve`. Like
	 * `replace`, `delete` only ever has one range to resolve against, so
	 * identifier resolution uses the small WriteVerbIdentifierResolver
	 * sequence rather than the QueryNormalizer/SemanticAnalyzer/
	 * QueryOptimizer machinery `retrieve` needs for joins/subqueries.
	 */
	class QuelToSQLDelete {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/** @var string|null Schema that qualifies routine names, or null for none */
		private ?string $routineSchema;
		private SQLSerializer $serializer;

		/**
		 * QuelToSQLDelete constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 * @param string|null $routineSchema Schema that qualifies routine names, or null for none
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, ?string $routineSchema) {
			$this->entityStore = $entityStore;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->routineSchema = $routineSchema;
			// Only needs EntityStore (see Serializer's constructor) — built
			// here so WriteVerbParameterNormalizer denormalizes a WHERE
			// clause's bound-parameter values exactly like append/replace do.
			$this->serializer = new SQLSerializer($entityStore);
		}

		/**
		 * Compiles a `delete <range> where ...` statement to SQL — a real
		 * DELETE, or a soft-delete UPDATE when applicable (see this class's
		 * docblock).
		 * @param AstDelete $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException|QuelException|EntityResolutionException
		 */
		public function convertToSQL(AstDelete $statement, array &$parameters): string {
			// The WHERE clause's identifiers need a resolved type/range
			// before they can compile to SQL.
			WriteVerbIdentifierResolver::resolve($statement, $this->entityStore);

			$range = $statement->getRange();

			// Normalize bound-parameter values compared against a real entity
			// column (e.g. `where u.deletedAt > :since`) before compiling the
			// WHERE clause to SQL — see WriteVerbParameterNormalizer's
			// docblock. `delete` bypasses UnitOfWork entirely, so without
			// this a raw PHP value (a \DateTime object, a json column's
			// array, a backed enum) would reach the driver unconverted.
			$metadata = $this->entityStore->getMetadata($range->getEntityName());
			$conditions = $statement->getConditionsOrFail();

			// Datetime comparisons work in Unix timestamps, as in retrieve
			$conditions->accept(new NormalizeDateTime($this->entityStore));
			$conditions->accept(new WriteVerbParameterNormalizer($metadata, $this->serializer, $parameters));
			$conditions->accept(new CoerceDateTimeParameters($parameters));

			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform, $this->routineSchema);
			$whereSql = $builder->visitConditionAndReturnSQL($conditions);

			return $this->buildStatement($range, $metadata, $whereSql, (bool)$statement->getDirective('ignoreSoftDelete'), $range->getName());
		}

		/**
		 * Compiles a routine's current-row `delete x` against the cursor's source range, soft-deleting like `delete`.
		 * On SQL Server the table is left unaliased, the documented `WHERE CURRENT OF` form.
		 * @param AstRangeDatabase $range The cursor's source range
		 * @param string $rowCondition SQL condition selecting the current row, e.g. `CURRENT OF cursor`
		 * @return string
		 */
		public function convertCurrentRowToSQL(AstRangeDatabase $range, string $rowCondition): string {
			$metadata = $this->entityStore->getMetadata($range->getEntityName());
			$alias = $this->platform->supportsAliasAfterDmlTarget() ? $range->getName() : null;
			return $this->buildStatement($range, $metadata, $rowCondition, false, $alias);
		}

		/**
		 * Builds the DELETE, or the soft-delete UPDATE when the entity has one and it isn't ignored.
		 * @param AstRangeDatabase $range Target range
		 * @param EntityMetadataRecord $metadata Target entity metadata
		 * @param string $whereSql Compiled WHERE condition
		 * @param bool $ignoreSoftDelete True to always emit a real DELETE
		 * @param string|null $alias Range alias of the target table, or null for an unaliased table
		 * @return string
		 */
		private function buildStatement(AstRangeDatabase $range, EntityMetadataRecord $metadata, string $whereSql, bool $ignoreSoftDelete, ?string $alias): string {
			$tableName = RangeTableName::resolve($range, $this->entityStore);
			$softDeleteSetClause = $ignoreSoftDelete ? null : $this->buildSoftDeleteSetClause($metadata, $alias);

			if ($alias === null) {
				$table = $this->identifierQuoter->quoteIdentifier($tableName);

				return $softDeleteSetClause !== null
					? "UPDATE {$table} SET {$softDeleteSetClause} WHERE {$whereSql}"
					: "DELETE FROM {$table} WHERE {$whereSql}";
			}

			if ($softDeleteSetClause !== null) {
				return AliasedDmlSql::update($tableName, $alias, $softDeleteSetClause, $whereSql, $this->identifierQuoter, $this->platform);
			}

			return AliasedDmlSql::delete($tableName, $alias, $whereSql, $this->identifierQuoter, $this->platform);
		}

		/**
		 * Builds the SET fragment that marks a row soft-deleted, or null (a real DELETE) when the entity
		 * has no soft-delete column of a type InjectSoftDeleteCondition recognises.
		 * @param EntityMetadataRecord $metadata
		 * @param string|null $alias The DELETE/UPDATE statement's own range alias, or null when the table is unaliased
		 * @return string|null
		 */
		private function buildSoftDeleteSetClause(EntityMetadataRecord $metadata, ?string $alias): ?string {
			if (!$metadata->hasSoftDelete()) {
				return null;
			}

			// softDeleteColumn is non-null here: EntityMetadataBuilder always
			// sets it together with softDeleteProperty, which hasSoftDelete()
			// just confirmed is set. The assertion satisfies PHPStan without
			// a runtime cost — same pattern InjectSoftDeleteCondition uses.
			$softDeleteColumn = $metadata->softDeleteColumn ?? throw new \LogicException('buildSoftDeleteSetClause called on entity without @SoftDelete');
			$targetColumn = SetTargetColumnQuoter::quote($softDeleteColumn, $alias, $this->identifierQuoter, $this->platform);

			return match ($metadata->softDeleteColumnType) {
				// NULL means active; any timestamp means deleted — see InjectSoftDeleteCondition.
				'datetime' => "{$targetColumn} = " . $this->platform->getCurrentDatetimeFunction(),

				// false means active; true means deleted — see InjectSoftDeleteCondition.
				'boolean'  => "{$targetColumn} = " . ($this->platform->supportsBooleanLiterals() ? 'true' : '1'),

				default    => null,
			};
		}
	}
