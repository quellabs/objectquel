<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;

	/**
	 * Finds all concrete entity ranges that expose a given property name.
	 *
	 * Shared by UnqualifiedDatabasePropertyResolver (transformation) and
	 * UnqualifiedPropertyValidator (semantic checking) so that the lookup
	 * logic lives in one place and each consumer can decide what to do
	 * with zero, one, or multiple matches.
	 */
	class FindPropertyRange {

		/** @var EntityStore */
		private EntityStore $entityStore;

		/**
		 * Optional live connection used to look up a plain table's real
		 * columns. Null when the caller has none to offer (e.g. a
		 * single-range write-verb statement, where ambiguity between table
		 * ranges can't occur anyway) — findRanges() then falls back to
		 * treating every table range as a match, same as before this
		 * introspection existed.
		 * @var DatabaseAdapter|null
		 */
		private ?DatabaseAdapter $databaseAdapter;

		/**
		 * Per-instance cache of table columns already looked up, keyed by
		 * table name. Null value means introspection failed (e.g. the table
		 * doesn't exist yet) and the permissive fallback should be used.
		 * @var array<string, array<string, mixed>|null>
		 */
		private array $tableColumnsCache = [];

		/**
		 * PropertyRangeFinder constructor
		 * @param EntityStore $entityStore Store containing entity/property metadata
		 * @param DatabaseAdapter|null $databaseAdapter Used to check a plain table's
		 *        real columns instead of blindly matching every table range
		 */
		public function __construct(EntityStore $entityStore, ?DatabaseAdapter $databaseAdapter = null) {
			$this->entityStore = $entityStore;
			$this->databaseAdapter = $databaseAdapter;
		}
		
		/**
		 * Returns all ranges that expose the given property, either as a scalar
		 * column (@Column) or as a relation (@OneToOne, @ManyToOne, @InverseOf)
		 * on a concrete entity range, or by real column lookup for a plain-table
		 * range (see tableHasColumn()). Subquery and JSON ranges are skipped
		 * because they have no metadata to inspect either way.
		 *
		 * @param string $propertyName The bare property name to look up
		 * @param AstRange[] $ranges All ranges declared in the query
		 * @return AstRange[] All ranges that own this property (may be empty or multiple)
		 * @throws EntityResolutionException
		 */
		protected function findRanges(string $propertyName, array $ranges): array {
			$matches = [];

			foreach ($ranges as $range) {
				if ($range instanceof AstRangeTable) {
					if ($this->tableHasColumn($range, $propertyName)) {
						$matches[] = $range;
					}

					continue;
				}

				// Only concrete entity ranges have EntityStore metadata
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}

				// Fetch the entity name
				$entityName = $range->getEntityName();

				// Check scalar columns first (@Column-annotated properties)
				$metadata = $this->entityStore->getMetadata($entityName);

				if (isset($metadata->columnMap[$propertyName])) {
					$matches[] = $range;
					continue;
				}

				// Check all relation types (@OneToOne, @ManyToOne, @InverseOf)
				$relations = array_merge(
					$metadata->getOneToOneDependencies(),
					$metadata->getManyToOneDependencies(),
					$metadata->getInverseOfDependencies(),
				);

				if (isset($relations[$propertyName])) {
					$matches[] = $range;
				}
			}

			return $matches;
		}

		/**
		 * Whether a plain-table range actually has the given column, per the
		 * live database schema. Falls back to treating the property as present
		 * (the pre-introspection behavior) when no DatabaseAdapter was supplied,
		 * or when introspection itself fails — e.g. the table doesn't exist yet
		 * (a `create table` statement earlier in the same request) — so a
		 * lookup failure can never turn into a harder error than "ambiguous,
		 * please qualify".
		 * @param AstRangeTable $range
		 * @param string $propertyName
		 * @return bool
		 */
		private function tableHasColumn(AstRangeTable $range, string $propertyName): bool {
			if ($this->databaseAdapter === null) {
				return true;
			}

			$tableName = $range->getTableName();

			if (!array_key_exists($tableName, $this->tableColumnsCache)) {
				try {
					$this->tableColumnsCache[$tableName] = $this->databaseAdapter->getColumns($tableName);
				} catch (\Throwable $e) {
					$this->tableColumnsCache[$tableName] = null;
				}
			}

			$columns = $this->tableColumnsCache[$tableName];

			return $columns === null || isset($columns[$propertyName]);
		}
	}