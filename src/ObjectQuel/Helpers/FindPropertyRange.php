<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	
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
		 * Returns all concrete entity ranges that expose the given property,
		 * either as a scalar column (@Column) or as a relation (@OneToOne,
		 * @ManyToOne, @OneToMany). Subquery and JSON ranges are skipped because
		 * they have no EntityStore metadata to inspect.
		 *
		 * @param string $propertyName The bare property name to look up
		 * @param AstRange[] $ranges All ranges declared in the query
		 * @return AstRangeDatabase[] All ranges that own this property (may be empty or multiple)
		 * @throws EntityResolutionException
		 */
		protected function findRanges(string $propertyName, array $ranges): array {
			$matches = [];
			
			foreach ($ranges as $range) {
				// Only concrete entity ranges have EntityStore metadata
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Fetch the entity name
				$entityName = $range->getEntityName();
				
				// Check scalar columns first (@Column-annotated properties)
				$columnMap = $this->entityStore->getColumnMap($entityName);
				
				if (isset($columnMap[$propertyName])) {
					$matches[] = $range;
					continue;
				}
				
				// Check all relation types (@OneToOne, @ManyToOne, @OneToMany)
				$relations = array_merge(
					$this->entityStore->getOneToOneDependencies($entityName),
					$this->entityStore->getManyToOneDependencies($entityName),
					$this->entityStore->getOneToManyDependencies($entityName)
				);
				
				if (isset($relations[$propertyName])) {
					$matches[] = $range;
				}
			}
			
			return $matches;
		}
	}