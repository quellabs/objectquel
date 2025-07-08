<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Serializers;
	
	use Quellabs\ObjectQuel\EntityStore;
	
	class SQLSerializer extends Serializer {
		
		/**
		 * SQLSerializer constructor
		 * Initializes the required handlers and readers.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			parent::__construct($entityStore);
		}
		
		/**
		 * Extracts all values from the entity that are marked as Column.
		 * @param object $entity The entity from which the values must be extracted.
		 * @return array An array with property names as keys and their values.
		 */
		public function serialize(object $entity): array {
			// Serialize the data
			$serializedData = parent::serialize($entity);
			
			// Retrieve the column map (property > database column)
			$columnMap = $this->entityStore->getColumnMap($entity);
			
			// Return updates data
			return array_combine(
				array_values($columnMap),
				array_values($serializedData),
			);
		}
		
		/**
		 * Injects the given values into the entity.
		 * @param object $entity The entity into which the values must be injected.
		 * @param array $values The values to be injected, with property names as keys.
		 * @return void
		 */
		public function deserialize(object $entity, array $values): void {
			// Retrieve the column map (property > database column)
			$columnMap = $this->entityStore->getColumnMap($entity);
			
			// Step 1: Create a temporary array with column names as both key and value
			// This is necessary because array_intersect_key() works with array keys
			$tempColumnMap = array_combine(
				array_values($columnMap),
				array_values($columnMap)
			);
			
			// Step 2: Filter the keys that exist in both $columnMap and $values
			// array_intersect_key() keeps only the keys from $tempColumnMap that also exist in $values
			$filteredKeys = array_intersect_key($tempColumnMap, $values);
			
			// Step 3: Create the final result array
			// The keys of $filteredKeys are now the property names we want to use
			// We map each key to its corresponding value in $values
			$result = array_combine(
				array_keys($filteredKeys),
				array_map(
					fn($key) => $values[$key],
					array_keys($filteredKeys)
				)
			);
			
			// Use the parent method to deserialize with the filtered and transformed data
			parent::deserialize($entity, $result);
		}
	}