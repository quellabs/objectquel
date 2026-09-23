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
	 * A soft-deletable entity compiles `delete` to an UPDATE of its soft-delete
	 * column instead of a real DELETE, unless `@ignoreSoftDelete true` is set.
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
		 * Compiles a `delete <range> where ...` statement to SQL (see class docblock).
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

			$tableName = RangeTableName::resolve($range, $this->entityStore);
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform);
			$whereSql = $builder->visitNodeAndReturnSQL($conditions);

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
		 * Builds the `col = <sql>` SET-clause fragment marking a row soft-deleted,
		 * or null when the entity has no recognised soft-delete column type.
		 * @param EntityMetadataRecord $metadata
		 * @param string $alias The DELETE/UPDATE statement's own range alias
		 * @return string|null
		 */
		private function buildSoftDeleteSetClause(EntityMetadataRecord $metadata, string $alias): ?string {
			if (!$metadata->hasSoftDelete()) {
				return null;
			}

			// Non-null here: hasSoftDelete() confirmed softDeleteProperty is set.
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
