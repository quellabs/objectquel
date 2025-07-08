<?php
	
	namespace Quellabs\ObjectQuel\QueryManagement;
	
	use Quellabs\ObjectQuel\EntityStore;
	
	class QueryBuilder {
		
		private EntityStore $entityStore;
		
		/**
		 * QueryBuilder constructor
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Generates an array of range definitions for the main entity and its relationships.
		 * This method is used to define the ranges for the query that will be executed.
		 * It combines both ManyToOne and OneToMany dependencies to provide a comprehensive overview
		 * of the entity's relationships. If there are no ManyToOne dependencies,
		 * the main entity is added as a stand-alone range.
		 * @param string $entityType The entity type for which relationships should be retrieved.
		 * @return array An array with range definitions for the entity and its relationships.
		 */
		private function getRelationRanges(string $entityType): array {
			// The first range is always 'main'
			$ranges = ['main' => "range of main is {$entityType}"];
			
			// Find which entities have a relationship with this entity and process them
			$rangeCounter = 0;

			foreach($this->entityStore->getDependentEntities($entityType) as $dependentEntityType) {
				$this->processOneToOneDependencies($entityType, $dependentEntityType, $ranges, $rangeCounter);
				
				// Process ManyToOne relationships and add them to the ranges.
				$this->processManyToOneDependencies($entityType, $dependentEntityType, $ranges, $rangeCounter);
			}
			
			// Return the range list
			return $ranges;
		}
		
		/**
		 * Convert an associative array to a string representation
		 * This method converts an associative array to a string representation where the keys and values are
		 * concatenated with the provided prefix and separated by the string "=". The resulting key-value pairs
		 * are then joined together using the string " AND".
		 * @param array<string, mixed> $parameters The associative array to be converted
		 * @param string $prefix The prefix to be applied to each key in the array
		 * @return string The converted string representation of the array
		 */
		private function parametersToString(array $parameters, string $prefix): string {
			$resultParts = [];
			
			foreach ($parameters as $key => $value) {
				$resultParts[] = "{$prefix}.{$key}=:{$key}";
			}
			
			return implode(" AND ", $resultParts);
		}
		
		/**
		 * Creates a unique alias for a range based on a provided counter.
		 * This method generates an alias by representing the current value of the range counter
		 * with an 'r' prefix. This alias is used to uniquely identify ranges within a query.
		 * @param int $rangeCounter A reference to the counter used to generate a unique alias.
		 * @return string The generated unique alias for the range.
		 */
		private function createAlias(int &$rangeCounter): string {
			return "r{$rangeCounter}";
		}
		
		/**
		 * Processes OneToOne dependencies for a given entity type.
		 * @param string $entityType The type of entity for which relationships are being processed.
		 * @param string $dependentEntityType
		 * @param array $ranges An array of existing ranges that needs to be extended.
		 * @param int $rangeCounter A counter to create unique aliases for ranges.
		 * @return void
		 */
		private function processOneToOneDependencies(string $entityType, string $dependentEntityType, array &$ranges, int &$rangeCounter): void {
			// Get all non-LAZY oneToOne dependencies
			$oneToOneDependencies = $this->entityStore->getOneToOneDependencies($dependentEntityType);
			$oneToOneDependenciesFiltered = array_filter($oneToOneDependencies, function($e) { return $e->getInversedBy() === null; });
			$oneToOneDependenciesFiltered = array_filter($oneToOneDependenciesFiltered, function($e) { return $e->getFetch() !== "LAZY"; });
			$oneToOneDependenciesFiltered = array_filter($oneToOneDependenciesFiltered, function($e) use ($entityType) {
				return $this->entityStore->normalizeEntityName($e->getTargetEntity()) === $entityType;
			});
			
			foreach ($oneToOneDependenciesFiltered as $propertyName => $relation) {
				// Create a unique alias for the range.
				$alias = $this->createAlias($rangeCounter);
				
				// Get relationship columns
				$inversedBy = $relation->getInversedBy();
				$relationColumn = $relation->getRelationColumn();
				
				// Add the range
				$ranges[$alias] = "range of {$alias} is {$dependentEntityType} via {$alias}.{$relationColumn}=main.{$inversedBy}";
				
				// Increment the range counter for the next unique range.
				++$rangeCounter;
			}
		}
		
		/**
		 * Processes ManyToOne dependencies for a given entity type.
		 * This method iterates through all ManyToOne relationships of the specified entity type and adds
		 * a new range to the provided array for each relationship. These ranges are
		 * used for building queries with related entities. Additionally, the main entity
		 * is set with a 'via' clause if ManyToOne relationships exist.
		 * @param string $entityType The type of entity for which relationships are being processed.
		 * @param string $dependentEntityType
		 * @param array $ranges An array of existing ranges that needs to be extended.
		 * @param int $rangeCounter A counter to create unique aliases for ranges.
		 * @return void
		 */
		private function processManyToOneDependencies(string $entityType, string $dependentEntityType, array &$ranges, int &$rangeCounter): void {
			// Get all non-LAZY manyToOne dependencies
			$manyToOneDependencies = $this->entityStore->getManyToOneDependencies($dependentEntityType);
			$manyToOneDependenciesFiltered = array_filter($manyToOneDependencies, function($e) { return $e->getFetch() !== "LAZY"; });
			$manyToOneDependenciesFiltered = array_filter($manyToOneDependenciesFiltered, function($e) use ($entityType) {
				return $this->entityStore->normalizeEntityName($e->getTargetEntity()) === $entityType;
			});
			
			foreach ($manyToOneDependenciesFiltered as $propertyName => $relation) {
				// Create a unique alias for the range.
				$alias = $this->createAlias($rangeCounter);
				
				// Get relationship columns
				$inversedBy = $relation->getInversedBy();
				$relationColumn = $relation->getRelationColumn();
				
				// Add the new range to the list.
				$ranges[$alias] = "range of {$alias} is {$dependentEntityType} via {$alias}.{$relationColumn}=main.{$inversedBy}";
				
				// Increment the range counter for the next unique range.
				++$rangeCounter;
			}
		}
		
		/**
		 * Prepares a query based on the given entity type and primary keys.
		 * This function generates a query string that can be used to retrieve an entity
		 * and its related entities.
		 * @param string $entityType The type of entity for which the query is being prepared.
		 * @param array $primaryKeys The primary keys for the entity.
		 * @return string The composed query string.
		 */
		public function prepareQuery(string $entityType, array $primaryKeys): string {
			// Get the range definitions for the entity's relationships.
			$relationRanges = $this->getRelationRanges($entityType);
			
			// Implement the range definitions in the query.
			$rangesImpl = implode("\n", $relationRanges);
			
			// Create a WHERE string based on the primary keys.
			$whereString = $this->parametersToString($primaryKeys, "main");
			
			// Combine everything into the final query string.
			return "{$rangesImpl}\nretrieve unique (" . implode(",", array_keys($relationRanges)) . ") where {$whereString}";
		}
	}