<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Serialization\Serializers\Serializer;
	
	class EntityHydrator {
		
		private UnitOfWork $unitOfWork;
		private EntityStore $entityStore;
		private Serializer $serializer;
		
		/**
		 * EntityHydrator constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->unitOfWork = $entityManager->getUnitOfWork();
			$this->entityStore = $entityManager->getEntityStore();
			$this->serializer = new Serializer($this->entityStore);
		}
		
		/**
		 * Quickly checks if the array contains any non-null values
		 * @param array<string|int, mixed> $array The array to check
		 * @return bool True if at least one non-null value exists
		 */
		private function isArrayPopulated(array $array): bool {
			return !empty(array_filter($array, fn($val) => $val !== null));
		}
		
		/**
		 * Remove a specified range prefix from the keys of an array.
		 * @param string $range The range prefix to remove from the array keys.
		 * @param array<string, mixed> $array The array to modify.
		 * @return array<string, mixed> The modified array with the range removed from the keys.
		 */
		private function removeRangeFromRow(string $range, array $array): array {
			$rangePrefix = $range . '.';
			$rangePrefixLength = strlen($rangePrefix);
			$modifiedArray = [];
			
			foreach ($array as $key => $value) {
				if (strncmp($key, $rangePrefix, $rangePrefixLength) === 0) {
					$modifiedArray[substr($key, $rangePrefixLength)] = $value;
				}
			}
			
			return $modifiedArray;
		}
		
		/**
		 * Initializes a proxy object with data
		 * @param ProxyInterface $proxy The proxy object to initialize
		 * @param array $data The data to populate the proxy with
		 * @return void
		 */
		private function initializeProxy(ProxyInterface $proxy, array $data): void {
			// Mark the proxy as initialized so it knows it has been loaded
			$proxy->setInitialized();
			
			// Deserialize the provided data into the proxy entity
			// This populates the proxy with all the properties from the data array
			$this->serializer->deserialize($proxy, $data);
			
			// Detach the entity from the Unit of Work
			// This allows the entity to be re-attached later as an existing entity
			// rather than being treated as a new entity to be persisted
			$this->unitOfWork->detach($proxy);
		}
		
		/**
		 * Processes a row of data into an entity object
		 * @param AstAlias $value The alias representing the entity to process
		 * @param array $filteredRow Data row containing entity properties
		 * @param array $relationCache Cache containing relationship information
		 * @return object|null The processed entity object or null if no data
		 */
		private function processEntity(AstAlias $value, array $filteredRow, array $relationCache): ?object {
			// Check if the array contains any meaningful data
			// If the array is empty or contains only null values, return null
			if (!$this->isArrayPopulated($filteredRow)) {
				return null;
			}
			
			// Extract metadata about the entity from the expression
			$expression = $value->getExpression();
			$entity = $this->entityStore->normalizeEntityName($expression->getEntityName()); // The entity class name
			$rangeName = $expression->getRange()->getName(); // The alias/range name in the query
			
			// Remove the range prefix from column names in the row data
			// This converts prefixed column names like "range.user_id" to just "user_id"
			$filteredRow = $this->removeRangeFromRow($rangeName, $filteredRow);
			
			// Extract only the primary key values from the filtered row
			// Uses array_intersect_key for better performance than manual filtering
			$primaryKeyValues = array_intersect_key($filteredRow, $relationCache['identifiers_flipped']);
			
			// Try to find an existing entity with the same primary key values
			// This prevents duplicate entities for the same database record
			$existingEntity = $this->unitOfWork->findEntity($entity, $primaryKeyValues);
			
			if ($existingEntity !== null) {
				// If the entity exists but is a non-initialized proxy,
				// initialize it with the current data
				if ($existingEntity instanceof ProxyInterface && !$existingEntity->isInitialized()) {
					$this->initializeProxy($existingEntity, $filteredRow);
				}
				
				// Mark the entity as "existing" in the Unit of Work
				// This ensures it will be tracked for changes but not inserted as new
				$this->unitOfWork->persistExisting($existingEntity);
				
				// Return the existing entity (possibly newly initialized)
				return $existingEntity;
			}
			
			// If no existing entity was found, create a new one and
			// populate it with data from the filtered row
			$newEntity = new $entity;
			$this->serializer->deserialize($newEntity, $filteredRow);
			
			// Add the new entity to the Unit of Work as an existing entity
			// (not as a new entity since it came from the database)
			$this->unitOfWork->persistExisting($newEntity);
			return $newEntity;
		}
		
		/**
		 * Extract all values out of the JSON row
		 * @param AstAlias $value
		 * @param array $row
		 * @param array|null $relationCache
		 * @return array
		 */
		private function processJsonAllValue(AstAlias $value, array $row, ?array $relationCache): array {
			return $this->removeRangeFromRow($value->getName(), $row);
		}
		
		/**
		 * Processes a single value from the query result.
		 * @param AstAlias $value The value to process.
		 * @param array<string, mixed> $row The current database row.
		 * @param array<string, mixed>|null $relationCache Cache containing relationship information.
		 * @return mixed The processed value (entity object, primitive value, or null).
		 */
		private function processValue(AstAlias $value, array $row, ?array $relationCache): mixed {
			$node = $value->getExpression();
			
			// Case 1: Process an entity (AstIdentifier with no next/parent nodes)
			if ($node instanceof AstIdentifier && !$node->hasNext() && !$node->hasParent()) {
				if ($node->getRange() instanceof AstRangeJsonSource) {
					return $this->processJsonAllValue($value, $row, $relationCache);
				} else {
					return $this->processEntityValue($value, $row, $relationCache);
				}
			}
			
			// Case 2: Process a property value (AstIdentifier with next node)
			if ($node instanceof AstIdentifier && $node->hasNext()) {
				return $this->processPropertyValue($value, $row, $node);
			}
			
			// Case 3: Process a simple value (direct lookup from row)
			return $row[$value->getName()] ?? null;
		}
		
		/**
		 * Processes an entity value from the query result.
		 * @param AstAlias $value The value representing the entity.
		 * @param array<string, mixed> $row The current database row.
		 * @param array<string, mixed>|null $relationCache Cache containing relationship information.
		 * @return object|null The processed entity object or null if no data.
		 */
		private function processEntityValue(AstAlias $value, array $row, ?array $relationCache): ?object {
			// Early return if no relation cache is provided
			// This suggests there's no relationship data available for processing
			if ($relationCache === null) {
				return null;
			}
			
			// Filter the row to only include columns relevant to this entity.
			// Uses the flipped keys from relationCache to identify relevant columns.
			// This is used to extract only the fields belonging to this entity
			// from a potentially larger result set that may include joined tables
			$filteredRow = array_intersect_key($row, $relationCache["keys_flipped"]);
			
			// Delegate to a separate method to transform the filtered row data into an entity object
			// Passes along the entity alias, filtered row data, and relation cache for context
			// The processEntity method likely handles instantiation and population of the entity
			return $this->processEntity($value, $filteredRow, $relationCache);
		}
		
		/**
		 * Processes a property value from the query result.
		 * @param AstAlias $value The value representing the property.
		 * @param array<string, mixed> $row The current database row.
		 * @param AstIdentifier $node The AST node with property information.
		 * @return mixed The processed property value.
		 */
		private function processPropertyValue(AstAlias $value, array $row, AstIdentifier $node): mixed {
			// Extract the raw value from the database row using the alias name as key
			// Return null if the key doesn't exist in the row
			$rawValue = $row[$value->getName()] ?? null;
			
			// Early return if no value was found
			if ($rawValue === null) {
				return null;
			}
			
			// Get the entity name from the node
			$entityName = $node->getEntityName();
			
			// Early return if no entity was found
			if ($entityName === null) {
				return null;
			}
			
			// Get the property name from the next node in the chain
			$propertyName = $node->getNext()->getName();
			
			try {
				// Retrieve annotations for the entity from the entity store
				$annotations = $this->entityStore->getAnnotations($entityName);
				
				// If no annotations exist for this property, return the raw value unchanged
				if (!isset($annotations[$propertyName])) {
					return $rawValue;
				}
				
				// Iterate through all annotations for this property
				foreach ($annotations[$propertyName] as $annotation) {
					// Check if the annotation is a Column type
					if ($annotation instanceof Column) {
						// If it's a Column, use the serializer from the unit of work
						// to convert the raw database value to its proper PHP type
						return $this->unitOfWork->getSerializer()->normalizeValue($annotation, $rawValue);
					}
				}
				
				// If we didn't find a Column annotation, return the raw value unchanged
				return $rawValue;
			} catch (\Exception $e) {
				// Silently handle any exceptions that occur during annotation processing
				// This could be improved by adding proper logging here
				// Consider logging the exception
				return $rawValue;
			}
		}
		
		/**
		 * Processes a database result row into a structured result based on the AST.
		 * @param array $ast Abstract Syntax Tree representing the query structure.
		 * @param array $row Raw database row from the query result.
		 * @param array $relationCache Cache of relationship information for entity mapping.
		 * @param array &$entities Reference to collection of unique entity objects for tracking.
		 * @return array Processed row with values mapped according to the AST.
		 */
		private function processRow(array $ast, array $row, array $relationCache, array &$entities): array {
			// Initialize the result row as an empty array
			$resultRow = [];
			
			// Process each value node in the abstract syntax tree
			foreach ($ast as $value) {
				// Skip the value if designated to do so
				if (!$value->isVisibleInResult()) {
					continue;
				}
				
				// Get the alias name for this value in the result set
				$name = $value->getName();
				
				// Determine if this value represents an entity (top-level identifier without parent or next nodes)
				// This distinguishes between entity objects and scalar property values
				$isEntity = $value->getExpression() instanceof AstIdentifier &&
					!$value->getExpression()->getRange() instanceof AstRangeJsonSource &&
					!$value->getExpression()->hasParent() &&
					!$value->getExpression()->hasNext();
				
				// If it's an entity, get the range name (typically the table/entity name in the query)
				$rangeName = $isEntity ? $value->getExpression()->getRange()->getName() : null;
				
				// Process the current value based on its type:
				// - For entities: pass the relation cache specific to this entity
				// - For properties: pass null for the relation cache
				$processedValue = $this->processValue(
					$value,
					$row,
					$isEntity ? $relationCache[$rangeName] : null
				);
				
				// Store the processed value in the result row using the alias name as key
				$resultRow[$name] = $processedValue;
				
				// If the value is an entity and not null, track it in the entities collection
				// This helps avoid duplicate processing and enables relationship loading
				if ($isEntity && ($processedValue !== null)) {
					// Generate a unique hash for the entity object
					$hash = spl_object_id($processedValue);
					
					// Only add the entity to the tracking collection if not already present
					// This ensures we maintain a set of unique entity instances
					if (!isset($entities[$hash])) {
						$entities[$hash] = $processedValue;
					}
				}
			}
			
			// Return the fully processed row with all values mapped according to the AST
			return $resultRow;
		}
		
		/**
		 * Initialiseer de relation cache op basis van de eerste rij en het AST.
		 * @param array $ast
		 * @param array $row
		 * @return array
		 */
		private function buildRelationCache(array $ast, array $row): array {
			$relationCache = [];
			
			foreach ($ast as $value) {
				$expression = $value->getExpression();
				
				if (!$expression instanceof AstIdentifier) {
					continue;
				}
				
				if ($expression->hasParent()) {
					continue;
				}
				
				// Check if entity is already cached
				$rangeName = $expression->getRange()->getName();
				$rangeNameLength = strlen($rangeName) + 1;
				$class = $expression->getEntityName();
				
				if (($class !== null) && !isset($relationCache[$rangeName])) {
					$keys = [];
					$identifierKeys = $this->entityStore->getIdentifierKeys($class);
					
					// Collect matching keys
					foreach ($row as $rowKey => $rowValue) {
						if (strncmp($rowKey, "{$rangeName}.", $rangeNameLength) === 0) {
							$keys[] = $rowKey;
						}
					}
					
					$relationCache[$rangeName] = [
						'identifiers'         => $identifierKeys,
						'identifiers_flipped' => array_flip($identifierKeys),
						'keys'                => $keys,
						'keys_flipped'        => array_flip($keys)
					];
				}
			}
			
			return $relationCache;
		}
		
		/**
		 * Converts raw database query results into hydrated entity objects.
		 * @param array $ast Abstract Syntax Tree representing the query structure.
		 * @param array $data Raw database rows from the query result.
		 * @return array An associative array containing processed result rows and unique entity objects.
		 */
		public function hydrateEntities(array $ast, array $data): array {
			// Flag to identify the first row (used for initializing relation cache)
			$first = true;
			
			// Collection to track unique entity objects across all rows
			$entities = [];
			
			// Storage for processed result rows
			$resultRows = [];
			
			// Cache for relationship information to optimize entity mapping
			// This is built once from the first row and reused for subsequent rows
			$relationCache = [];
			
			// Process each row from the database result
			foreach ($data as $row) {
				// For the first row only, build a relation cache that maps
				// AST nodes to their corresponding database columns
				if ($first) {
					$relationCache = $this->buildRelationCache($ast, $row);
					$first = false;
				}
				
				// Process the current row using the AST and relation cache
				// Also pass the entities collection by reference to track unique entities
				$resultRows[] = $this->processRow($ast, $row, $relationCache, $entities);
			}
			
			// Return both the processed result rows and the collection of unique entities
			// - 'result' contains the transformed data as requested in the query
			// - 'entities' contains all unique entity objects that were hydrated,
			//   which may be used for relationship loading or change tracking
			return [
				'result'   => $resultRows,
				'entities' => $entities
			];
		}
	}