<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\RangeTableName;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SetTargetColumnQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbParameterNormalizer;
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
		private SQLSerializer $serializer;

		/**
		 * QuelToSQLDelete constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->entityStore = $entityStore;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
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
		 * @throws SemanticException
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
			$normalizer = new WriteVerbParameterNormalizer($metadata, $this->serializer, $parameters);
			$statement->getConditionsOrFail()->accept($normalizer);

			$tableName = RangeTableName::resolve($range, $this->entityStore);
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform);
			$whereSql = $builder->visitNodeAndReturnSQL($statement->getConditionsOrFail());

			if (!$statement->getDirective('ignoreSoftDelete')) {
				$softDeleteSetClause = $this->buildSoftDeleteSetClause($metadata, $range->getName());

				if ($softDeleteSetClause !== null) {
					return sprintf(
						'UPDATE %s as %s SET %s WHERE %s',
						$this->identifierQuoter->quoteIdentifier($tableName),
						$this->identifierQuoter->quoteIdentifier($range->getName()),
						$softDeleteSetClause,
						$whereSql
					);
				}
			}

			return sprintf(
				'DELETE FROM %s as %s WHERE %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->identifierQuoter->quoteIdentifier($range->getName()),
				$whereSql
			);
		}

		/**
		 * Builds the `` `col` = <sql> `` SET-clause fragment that marks a row
		 * soft-deleted, or null when the entity has no soft-delete column or
		 * its column type isn't one of the two InjectSoftDeleteCondition
		 * (the read-side filter) recognises — falling back to a real DELETE
		 * in that case rather than emitting a broken UPDATE, same fail-open
		 * behavior InjectSoftDeleteCondition::buildCondition() uses.
		 *
		 * A raw SQL fragment, not a bound parameter — mirrors
		 * VersionValueHandler::buildVersionSetClause()'s 'datetime' case,
		 * which uses the same engine-appropriate "current datetime"
		 * expression for the same reason (this is a system-generated value,
		 * not user-supplied data going through AssignmentValidator).
		 * @param EntityMetadataRecord $metadata
		 * @param string $alias The DELETE/UPDATE statement's own range alias
		 * @return string|null
		 */
		private function buildSoftDeleteSetClause(EntityMetadataRecord $metadata, string $alias): ?string {
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
				'boolean'  => "{$targetColumn} = true",

				default    => null,
			};
		}
	}
