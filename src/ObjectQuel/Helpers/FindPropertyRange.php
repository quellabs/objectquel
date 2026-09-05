<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
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
		 * PropertyRangeFinder constructor
		 * @param EntityStore $entityStore Store containing entity/property metadata
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Returns all ranges that expose the given property, either as a scalar
		 * column (@Column) or as a relation (@OneToOne, @ManyToOne, @InverseOf)
		 * on a concrete entity range, or unconditionally for a plain-table range.
		 * Plain-table ranges have no column catalog to check against, so any
		 * bare property name is treated as a potential match for them — the
		 * same way a qualified reference (`x.id`) is accepted without checking
		 * that the table actually has an `id` column. Subquery and JSON ranges
		 * are skipped because they have no metadata to inspect either way.
		 *
		 * @param string $propertyName The bare property name to look up
		 * @param AstRange[] $ranges All ranges declared in the query
		 * @return AstRange[] All ranges that own this property (may be empty or multiple)
		 * @throws EntityResolutionException
		 */
		protected function findRanges(string $propertyName, array $ranges): array {
			$matches = [];

			foreach ($ranges as $range) {
				// Plain-table ranges have no metadata to check the property
				// against, so treat them as always exposing it.
				if ($range instanceof AstRangeTable) {
					$matches[] = $range;
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
	}