<?php
	
	namespace Quellabs\ObjectQuel\Execution\Hydration;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\SourceField;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\HydrationException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\Serializer;
	
	/**
	 * @phpstan-type RelationCacheEntry array{
	 *     identifiers: array<int, string>,
	 *     identifiers_flipped: array<string, int>,
	 *     keys: array<int, string>,
	 *     keys_flipped: array<string, int>
	 * }
	 *
	 * @phpstan-type RelationCache array<string, RelationCacheEntry>
	 */
	class EntityHydrator {
		
		private UnitOfWork $unitOfWork;
		private EntityStore $entityStore;
		private Serializer $serializer;
		private PropertyHandler $propertyHandler;
		
		/**
		 * EntityHydrator constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->unitOfWork = $entityManager->getUnitOfWork();
			$this->entityStore = $entityManager->getEntityStore();
			$this->serializer = new Serializer($this->entityStore);
			$this->propertyHandler = $entityManager->getPropertyHandler();
		}
		
		
		/**
		 * Converts raw database query results into hydrated entity objects.
		 * @param array<int, AstAlias> $ast Abstract Syntax Tree representing the query structure.
		 * @param array<int, array<string, mixed>> $data Raw database rows from the query result.
		 * @return array{
		 *     result: array<int, array<string, mixed>>,
		 *     entities: array<string, object>
		 * } An associative array containing processed result rows and unique entity objects.
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		public function hydrateEntities(array $ast, array $data): array {
			// Flag to identify the first row (used for initializing relation cache)
			$first = true;
			
			/**
			 * Collection to track unique entity objects across all rows
			 * @var array<string, object> $entities
			 */
			$entities = [];
			
			// Storage for processed result rows
			$resultRows = [];
			
			// Cache for relationship information to optimize entity mapping
			// This is built once from the first row and reused for subsequent rows
			$relationCache = [];
			
			// Collect the names of all JSON source ranges declared in the AST.
			// This set is built once here and passed down to processEntity() so the
			// enrichment step can identify JSON-prefixed keys in the flat row without
			// re-scanning the AST on every row or every entity.
			$jsonRangeNames = $this->collectJsonRangeNames($ast);
			
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
				$resultRows[] = $this->processRow($ast, $row, $relationCache, $entities, $jsonRangeNames);
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
		 * @param array<string, mixed> $data The data to populate the proxy with
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
		 * Scans the AST once to collect the names of all JSON source ranges.
		 *
		 * The resulting set is used by enrichEntityFromJsonSources() to distinguish
		 * JSON-originated keys from database-originated keys in the flat merged row,
		 * and to detect ambiguity when a @SourceField annotation omits the range parameter.
		 *
		 * @param array<int, AstAlias> $ast The full retrieve AST for the current query.
		 * @return array<string, true> A set of range alias names, keyed for O(1) lookup.
		 */
		private function collectJsonRangeNames(array $ast): array {
			$jsonRangeNames = [];
			
			foreach ($ast as $alias) {
				$expression = $alias->getExpression();
				
				// Only top-level identifiers carry range information
				if (!$expression instanceof AstIdentifier) {
					continue;
				}
				
				$range = $expression->getRange();
				
				// Record the alias of every JSON source range found in the AST
				if ($range instanceof AstRangeJsonSource) {
					$jsonRangeNames[$range->getName()] = true;
				}
			}
			
			return $jsonRangeNames;
		}
		
		/**
		 * Resolves which JSON range to use for a given @SourceField annotation.
		 *
		 * When the annotation provides an explicit range, that name is returned
		 * directly (the caller will handle "range not present in row" as a no-op).
		 * When no range is specified, the method infers it from the set of JSON
		 * ranges that are actually present in the current row:
		 *   - Exactly one JSON range present → use it automatically.
		 *   - Multiple JSON ranges present   → throw SemanticException; the developer
		 *     must add an explicit range to the annotation to resolve the ambiguity.
		 *   - No JSON ranges present         → return null (no-op).
		 *
		 * @param SourceField $annotation The @SourceField annotation being resolved.
		 * @param string $propertyName The entity property name, used for error messages.
		 * @param array<string, true> $presentJsonRanges
		 *        JSON range names that actually appear as prefixes in the current row,
		 *        keyed by range name for O(1) lookup.
		 * @return string|null The resolved range name, or null when no JSON data is available.
		 */
		private function resolveJsonRange(SourceField $annotation, string $propertyName, array $presentJsonRanges): ?string {
			// Explicit range declared on the annotation — use it as-is.
			// If that range is not present in the row the caller applies no-op logic.
			$explicitRange = $annotation->getRange();
			
			if ($explicitRange !== null) {
				return $explicitRange;
			}
			
			// No explicit range: infer from the JSON ranges present in this row
			$count = count($presentJsonRanges);
			
			if ($count === 0) {
				// No JSON data in the row at all — nothing to enrich from
				return null;
			}
			
			if ($count === 1) {
				// Exactly one JSON range: safe to infer automatically
				return array_key_first($presentJsonRanges);
			}
			
			// Ambiguous — multiple JSON ranges, no explicit range declared on the annotation.
			// Unreachable in a validated query; the semantic analyser catches this first.
			return null;
		}
		
		/**
		 * Applies @SourceField annotations to an entity by writing values from JSON source
		 * ranges in the current row directly onto the entity's properties.
		 *
		 * This method is called from processEntity() after the entity has been resolved
		 * or created. It reads every property of the entity class that carries a @SourceField
		 * annotation, resolves the correct JSON range (explicit or inferred), and sets the
		 * property value via PropertyHandler when a matching key exists in the row.
		 *
		 * No-op conditions (the property is left untouched):
		 *  - The resolved range is not present in this row.
		 *  - The field key does not exist in the JSON range's data.
		 *
		 * @param object $entity The fully resolved entity to enrich.
		 * @param string $entityName Fully qualified class name of the entity.
		 * @param array<string, mixed> $fullRow The complete merged result row, containing
		 *                                              prefixed keys from all stages (e.g. "product.name").
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST,
		 *                                              used to build the set of ranges present in the row.
		 * @return void
		 * @throws EntityResolutionException   When the entity class cannot be resolved.
		 */
		private function enrichEntityFromJsonSources(object $entity, string $entityName, array $fullRow, array $jsonRangeNames): void {
			// Collect @SourceField annotations for this entity class, keyed by property name.
			// getAnnotationsOfType() returns array<string, array<int, T>>.
			$metadata = $this->entityStore->getMetadata($entityName);
			$jsonFieldAnnotations = $metadata->getAnnotationsOfType(SourceField::class);
			
			// Nothing to do when the entity declares no @SourceField properties
			if (empty($jsonFieldAnnotations)) {
				return;
			}
			
			// Determine which JSON ranges are actually present in this row by intersecting
			// the AST-level set with keys that appear as prefixes in the flat row.
			// This subset drives ambiguity detection when a range is not explicitly declared.
			$presentJsonRanges = [];
			
			foreach ($jsonRangeNames as $rangeName => $_) {
				// A range is "present" when at least one of its prefixed keys exists in the row
				if (array_key_exists("{$rangeName}.", array_flip(
					array_map(fn($k) => substr($k, 0, strpos($k, '.') + 1), array_keys($fullRow))
				))) {
					$presentJsonRanges[$rangeName] = true;
				}
			}
			
			// Simpler and more efficient: rebuild presentJsonRanges by scanning row keys once
			$presentJsonRanges = [];
			
			foreach (array_keys($fullRow) as $rowKey) {
				// Row keys from JSON stages are always in the format "{rangeName}.{field}"
				$dotPos = strpos($rowKey, '.');
				
				if ($dotPos === false) {
					continue;
				}
				
				$prefix = substr($rowKey, 0, $dotPos);
				
				// Only count it as a present JSON range if it is known to the AST
				if (isset($jsonRangeNames[$prefix])) {
					$presentJsonRanges[$prefix] = true;
				}
			}
			
			// Process each property that carries a @SourceField annotation
			foreach ($jsonFieldAnnotations as $propertyName => $annotations) {
				foreach ($annotations as $annotation) {
					// Resolve which range to read from (explicit or inferred)
					$rangeName = $this->resolveJsonRange($annotation, $propertyName, $presentJsonRanges);
					
					// No usable range — skip this property
					if ($rangeName === null) {
						continue;
					}
					
					// The range was declared but is not present in this particular row — no-op
					if (!isset($presentJsonRanges[$rangeName])) {
						continue;
					}
					
					// Build the fully-qualified row key: "{rangeName}.{field}"
					$rowKey = "{$rangeName}.{$annotation->getField()}";
					
					// The field does not exist in the JSON data for this row — no-op
					if (!array_key_exists($rowKey, $fullRow)) {
						continue;
					}
					
					// Write the value directly onto the entity via PropertyHandler,
					// bypassing any setter so the entity stays passive
					$this->propertyHandler->set($entity, $propertyName, $fullRow[$rowKey]);
				}
			}
		}
		
		/**
		 * Processes a row of data into an entity object
		 * @param AstAlias $value The alias representing the entity to process
		 * @param array<string, mixed> $filteredRow Data row containing entity properties
		 * @param RelationCacheEntry $relationCache Cache containing relationship information
		 * @param array<string, mixed> $fullRow The complete unfiltered row from all stages,
		 *                                       used for @SourceField enrichment after the entity is resolved.
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST.
		 * @return object|null The processed entity object or null if no data
		 * @throws QuelException
		 * @throws HydrationException|EntityResolutionException
		 */
		private function processEntity(AstAlias $value, array $filteredRow, array $relationCache, array $fullRow, array $jsonRangeNames): ?object {
			// Check if the array contains any meaningful data
			// If the array is empty or contains only null values, return null
			if (!$this->isArrayPopulated($filteredRow)) {
				return null;
			}
			
			// Extract metadata about the entity from the expression
			$expression = $value->getExpression();
			
			// The expression has to be an AstIdentifier
			if (!$expression instanceof AstIdentifier) {
				throw new HydrationException("Expression should be of type AstIdentifier");
			}
			
			// The AstIdentifier has to have an entity
			$entityName = $expression->getEntityName();
			
			// Validate the existence of a entity
			if ($entityName === null) {
				throw new HydrationException("Missing entity name in the AstIdentifier");
			}
			
			// Resolve the entity
			$entity = $this->entityStore->resolveProxyClass($entityName);
			
			// Fetch the range
			$rangeName = $expression->getRange()?->getName();
			if ($rangeName === null) {
				throw new HydrationException("Missing range in the AstIdentifier");
			}
			
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
				
				// Apply @SourceField enrichment to the resolved existing entity
				$this->enrichEntityFromJsonSources($existingEntity, $entityName, $fullRow, $jsonRangeNames);
				
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
			
			// Apply @SourceField enrichment to the newly created entity
			$this->enrichEntityFromJsonSources($newEntity, $entityName, $fullRow, $jsonRangeNames);
			
			return $newEntity;
		}
		
		/**
		 * Extract all values out of the JSON row
		 * @param AstAlias $value
		 * @param array<string, mixed> $row
		 * @return array<string, mixed>
		 */
		private function processJsonAllValue(AstAlias $value, array $row): array {
			return $this->removeRangeFromRow($value->getName(), $row);
		}
		
		/**
		 * Processes a single value from the query result.
		 * @param AstAlias $value The value to process.
		 * @param array<string, mixed> $row The current database row.
		 * @param RelationCacheEntry|null $relationCache Cache containing relationship information.
		 * @param array<string, mixed> $fullRow The complete unfiltered row from all stages,
		 *                                       forwarded to processEntityValue() for @SourceField enrichment.
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST.
		 * @return mixed The processed value (entity object, primitive value, or null).
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		private function processValue(AstAlias $value, array $row, ?array $relationCache, array $fullRow, array $jsonRangeNames): mixed {
			$node = $value->getExpression();
			
			// Case 1: Process an entity (AstIdentifier with no next/parent nodes)
			if ($node instanceof AstIdentifier && !$node->hasNext() && !$node->hasParentIdentifier()) {
				// Process JSON
				if ($node->getRange() instanceof AstRangeJsonSource) {
					return $this->processJsonAllValue($value, $row);
				}
				
				// Subquery ranges have no entity name — they are derived tables, not mapped
				// entities. Return the raw scalar value directly from the row instead of
				// attempting entity hydration, which would fail with no relation cache entry.
				if ($node->getRange()?->getEntityName() === null) {
					return $row[$value->getName()] ?? null;
				}
				
				return $this->processEntityValue($value, $row, $relationCache, $fullRow, $jsonRangeNames);
			}
			
			// Case 2: Process a property value (AstIdentifier with next node)
			if ($node instanceof AstIdentifier && $node->hasNext()) {
				// Subquery range property (e.g. x.id where x is a derived table) —
				// no entity metadata exists, so return the raw value directly.
				if ($node->getRange()?->getEntityName() === null) {
					return $row[$value->getName()] ?? null;
				}
				
				return $this->processPropertyValue($row[$value->getName()] ?? null, $node);
			}
			
			// Case 3: Process a simple value (direct lookup from row)
			return $row[$value->getName()] ?? null;
		}
		
		/**
		 * Processes an entity value from the query result.
		 * @param AstAlias $value The value representing the entity.
		 * @param array<string, mixed> $row The current database row.
		 * @param RelationCacheEntry|null $relationCache Cache containing relationship information.
		 * @param array<string, mixed> $fullRow The complete unfiltered row from all stages,
		 *                                       passed through to processEntity() for @SourceField enrichment.
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST.
		 * @return object|null The processed entity object or null if no data.
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		private function processEntityValue(AstAlias $value, array $row, ?array $relationCache, array $fullRow, array $jsonRangeNames): ?object {
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
			return $this->processEntity($value, $filteredRow, $relationCache, $fullRow, $jsonRangeNames);
		}
		
		/**
		 * Processes a property value from the query result.
		 * @param mixed $rawValue
		 * @param AstIdentifier $node The AST node with property information.
		 * @return mixed The processed property value.
		 * @throws HydrationException|EntityResolutionException
		 */
		private function processPropertyValue(mixed $rawValue, AstIdentifier $node): mixed {
			// Early return if value is NULL
			if ($rawValue === null) {
				return null;
			}
			
			// Get the entity name from the node
			$entityName = $node->getEntityName();
			
			// Error when node has no attached entity
			if ($entityName === null) {
				throw new HydrationException("Missing entity name in the AstIdentifier");
			}
			
			// Get the property name from the next node in the chain
			$propertyName = $node->getNext()?->getName();
			
			// Error when property has no name (e.g. just the entity name was passed)
			if ($propertyName === null) {
				throw new HydrationException("Missing property name in the AstIdentifier");
			}
			
			// Retrieve annotations for the entity from the entity store
			$metadata = $this->entityStore->getMetadata($entityName);
			$annotations = $metadata->getAnnotations();
			
			// Iterate through all annotations for this property
			foreach ($annotations[$propertyName] ?? [] as $annotation) {
				// Check if the annotation is a Column type
				if (!$annotation instanceof Column) {
					continue;
				}
				
				// If it's a Column, use the serializer from the unit of work
				// to convert the raw database value to its proper PHP type
				return $this->unitOfWork->getSerializer()->normalizeValue($annotation, $rawValue);
			}
			
			// If we didn't find a Column annotation, throw as we cannot hydrate
			throw new HydrationException("No @Column annotation found for property '{$propertyName}' on '{$entityName}'");
		}
		
		/**
		 * Processes a database result row into a structured result based on the AST.
		 * @param array<int, AstAlias> $ast Abstract Syntax Tree representing the query structure.
		 * @param array<string, mixed> $row Raw database row from the query result.
		 * @param RelationCache $relationCache Cache of relationship information for entity mapping.
		 * @param array<string, object> $entities Reference to collection of unique entity objects for tracking.
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST,
		 *                                             forwarded to processValue() for @SourceField enrichment.
		 * @return array<string, mixed> Processed row with values mapped according to the AST.
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		private function processRow(array $ast, array $row, array $relationCache, array &$entities, array $jsonRangeNames): array {
			// Initialize the result row as an empty array
			$resultRow = [];
			
			// Process each value node in the abstract syntax tree
			foreach ($ast as $value) {
				// Skip the value if designated to do so
				if (!$value->showInResult()) {
					continue;
				}
				
				// Get the alias name for this value in the result set
				$name = $value->getName();
				
				// Determine if this value represents an entity (top-level identifier without parent or next nodes)
				// This distinguishes between entity objects and scalar property values
				$isEntity = $value->getExpression() instanceof AstIdentifier &&
					!$value->getExpression()->getRange() instanceof AstRangeJsonSource &&
					!$value->getExpression()->hasParentIdentifier() &&
					!$value->getExpression()->hasNext();
				
				// If it's an entity, get the range name (typically the table/entity name in the query)
				$rangeName = $isEntity ? $value->getExpression()->getRange()?->getName() : null;
				
				// Process the current value based on its type:
				// - For entities: pass the relation cache specific to this entity
				// - For properties: pass null for the relation cache
				// The full row is also forwarded so processEntity() can enrich via @SourceField
				$processedValue = $this->processValue(
					$value,
					$row,
					$isEntity ? $relationCache[$rangeName] : null,
					$row,        // full unfiltered row forwarded for @SourceField enrichment
					$jsonRangeNames
				);
				
				// Store the processed value in the result row using the alias name as key
				$resultRow[$name] = $processedValue;
				
				// If the value is an entity and not null, track it in the entities collection
				// This helps avoid duplicate processing and enables relationship loading
				if ($isEntity && is_object($processedValue)) {
					// Generate a unique hash for the entity object
					$hash = spl_object_hash($processedValue);
					
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
		 * Build the relation cache from the first row and the AST.
		 * @param array<int, AstAlias> $ast
		 * @param array<string, mixed> $row
		 * @return RelationCache
		 * @throws EntityResolutionException
		 */
		private function buildRelationCache(array $ast, array $row): array {
			$relationCache = [];
			
			foreach ($ast as $value) {
				// Only process top-level identifier expressions (no parent = no nested path)
				$expression = $value->getExpression();
				
				if (!$expression instanceof AstIdentifier || $expression->hasParentIdentifier()) {
					continue;
				}
				
				// Skip unresolved ranges, unresolved entity classes, and already-cached ranges
				$range = $expression->getRange();
				$class = $expression->getEntityName();
				
				if ($range === null || $class === null || isset($relationCache[$range->getName()])) {
					continue;
				}
				
				// Collect all row keys belonging to this range (e.g. "alias.field")
				$rangeName = $range->getName();
				
				$keys = array_keys(array_filter(
					$row,
					static fn($_, string $rowKey) => str_starts_with($rowKey, "{$rangeName}."),
					ARRAY_FILTER_USE_BOTH
				));
				
				// Store flipped variants for O(1) reverse lookup by callers
				$metadata = $this->entityStore->getMetadata($class);
				
				$relationCache[$rangeName] = [
					'identifiers'         => $metadata->identifierKeys,
					'identifiers_flipped' => array_flip($metadata->identifierKeys),
					'keys'                => $keys,
					'keys_flipped'        => array_flip($keys),
				];
			}
			
			return $relationCache;
		}
	}