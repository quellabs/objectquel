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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
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
	 *
	 * @phpstan-type HydrateResult array{
	 *     result: array<int, array<string, mixed>>,
	 *     entities: array<string, object>
	 * }
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
		 * @return HydrateResult Processed result rows and unique hydrated entity objects.
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		public function hydrateEntities(array $ast, array $data): array {
			// Collect JSON range names once up front so every row and entity can
			// identify JSON-prefixed keys without re-scanning the AST each time.
			$jsonRangeNames = $this->collectJsonRangeNames($ast);
			
			// Process each row from the database result
			$entities = [];
			$resultRows = [];
			$relationCache = [];
			
			foreach ($data as $row) {
				// The relation cache maps each entity range to its column keys and
				// primary key identifiers. It only needs to be built once because the
				// result set schema is the same for every row.
				if (empty($relationCache)) {
					$relationCache = $this->buildRelationCache($ast, $row);
				}
				
				// Process row
				$resultRows[] = $this->processRow($ast, $row, $relationCache, $entities, $jsonRangeNames);
			}
			
			// Return both the processed result rows and the collection of unique entities.
			// 'entities' may be used for relationship loading or change tracking.
			return [
				'result'   => $resultRows,
				'entities' => $entities
			];
		}
		
		/**
		 * Builds a per-range index of column keys and primary key identifiers from
		 * the first result row and the query AST.
		 *
		 * Only mapped entity ranges are indexed — JSON source ranges and subquery
		 * (derived-table) ranges have no entity metadata and are intentionally skipped.
		 * The cache is keyed by range alias name and reused for every subsequent row.
		 *
		 * @param array<int, AstAlias> $ast
		 * @param array<string, mixed> $row
		 * @return RelationCache
		 * @throws EntityResolutionException
		 */
		private function buildRelationCache(array $ast, array $row): array {
			$relationCache = [];
			
			foreach ($ast as $value) {
				$expression = $value->getExpression();
				
				// Only top-level identifiers carry range information.
				if (!$expression instanceof AstIdentifier || $expression->hasParentIdentifier()) {
					continue;
				}
				
				$range = $expression->getRange();
				$class = $expression->getEntityName();
				
				// Skip JSON source ranges, subquery ranges (no entity name), and
				// ranges already in the cache (duplicate alias in the SELECT list).
				if ($range === null || $class === null || isset($relationCache[$range->getName()])) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				// Collect all column keys belonging to this range from the first row
				// (e.g. "p.id", "p.name") so processEntity() can slice them out.
				$keys = array_keys(array_filter(
					$row,
					static fn($_, string $rowKey) => str_starts_with($rowKey, "{$rangeName}."),
					ARRAY_FILTER_USE_BOTH
				));
				
				// Fetch metadata
				$metadata = $this->entityStore->getMetadata($class);
				
				// Store both forward and flipped variants for O(1) lookup by callers.
				$relationCache[$rangeName] = [
					'identifiers'         => $metadata->identifierKeys,
					'identifiers_flipped' => array_flip($metadata->identifierKeys),
					'keys'                => $keys,
					'keys_flipped'        => array_flip($keys),
				];
			}
			
			return $relationCache;
		}
		
		/**
		 * Scans the AST once to collect the names of all JSON source ranges.
		 *
		 * The resulting set is passed down to enrichEntityFromJsonSources() so it can
		 * identify JSON-prefixed keys in the flat merged row and detect ambiguity when
		 * a @SourceField annotation omits the range parameter.
		 *
		 * @param array<int, AstAlias> $ast
		 * @return array<string, true> Range alias names keyed for O(1) lookup.
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
		 * Processes a single database result row into a keyed result array.
		 *
		 * Each alias in the AST becomes one key in the output. Entity aliases are
		 * hydrated via processEntity(); everything else (scalar properties, JSON
		 * ranges, subquery scalars) goes to processValue().
		 *
		 * @param array<int, AstAlias> $ast
		 * @param array<string, mixed> $row
		 * @param RelationCache $relationCache
		 * @param array<string, object> $entities Accumulator for unique hydrated entities, passed by reference.
		 * @param array<string, true> $jsonRangeNames
		 * @return array<string, mixed>
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		private function processRow(
			array $ast,
			array $row,
			array $relationCache,
			array &$entities,
			array $jsonRangeNames
		): array {
			$resultRow = [];
			
			foreach ($ast as $value) {
				// Value was tagged as hidden
				if (!$value->showInResult()) {
					continue;
				}
				
				if ($this->isEntityAlias($value)) {
					$cacheEntry = $this->resolveEntityCacheEntry($value, $relationCache);
					$processedValue = $this->processEntity($value, $row, $cacheEntry, $row, $jsonRangeNames);
					
					// Track unique entity instances so the caller can wire up relationships.
					if (is_object($processedValue)) {
						$entities[spl_object_hash($processedValue)] ??= $processedValue;
					}
				} else {
					$processedValue = $this->processValue($value, $row, $row, $jsonRangeNames);
				}
				
				$resultRow[$value->getName()] = $processedValue;
			}
			
			return $resultRow;
		}
		
		/**
		 * Returns true when an alias refers to a whole mapped entity rather than
		 * a property, JSON range, or scalar expression.
		 *
		 * An entity alias is a top-level AstIdentifier with no chained property
		 * and no parent identifier, whose range is a real database range (not a
		 * JSON source range).
		 *
		 * @param AstAlias $alias
		 * @return bool
		 */
		private function isEntityAlias(AstAlias $alias): bool {
			$node = $alias->getExpression();
			
			return
				$node instanceof AstIdentifier &&
				!$node->getRange() instanceof AstRangeJsonSource &&
				!$node->hasParentIdentifier() &&
				!$node->hasNext();
		}
		
		/**
		 * Looks up the RelationCacheEntry for an entity alias.
		 *
		 * buildRelationCache() creates an entry for every mapped entity range before
		 * any row is processed, so a missing entry is a programming error.
		 *
		 * @param AstAlias $alias
		 * @param RelationCache $relationCache
		 * @return RelationCacheEntry
		 * @throws \LogicException
		 */
		private function resolveEntityCacheEntry(AstAlias $alias, array $relationCache): array {
			/**
			 * isEntityAlias() has already confirmed the expression is an AstIdentifier.
			 * @var AstIdentifier $node
			 */
			$node = $alias->getExpression();
			$rangeName = $node->getRange()?->getName();
			
			if ($rangeName === null || !isset($relationCache[$rangeName])) {
				throw new \LogicException(
					"No relation cache entry for entity alias '{$alias->getName()}' (range '{$rangeName}'). " .
					"buildRelationCache() must build an entry for every entity range before processRow() is called."
				);
			}
			
			return $relationCache[$rangeName];
		}
		
		/**
		 * Resolves or creates the entity object for a given row.
		 *
		 * Slices the entity's own columns out of the full row first, then returns
		 * null when all of those columns are null — which happens on the right side
		 * of a LEFT JOIN when no matching row exists.
		 *
		 * @param AstAlias $value
		 * @param array<string, mixed> $row Full merged row from all joined ranges.
		 * @param RelationCacheEntry $relationCache
		 * @param array<string, mixed> $fullRow Complete merged row, forwarded for @SourceField enrichment.
		 * @param array<string, true> $jsonRangeNames
		 * @return object|null
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 */
		private function processEntity(AstAlias $value, array $row, array $relationCache, array $fullRow, array $jsonRangeNames): ?object {
			// Extract only the columns belonging to this entity range; the full row
			// contains columns from every joined range.
			$filteredRow = array_intersect_key($row, $relationCache['keys_flipped']);
			
			// All columns are null — no entity exists on this side of the join.
			if (!$this->isArrayPopulated($filteredRow)) {
				return null;
			}
			
			/**
			 * isEntityAlias() has already confirmed the expression is an AstIdentifier.
			 * @var AstIdentifier $expression
			 */
			$expression = $value->getExpression();
			
			// Extract and validate entity name
			$entityName = $expression->getEntityName();
			
			if ($entityName === null) {
				throw new \LogicException("Entity alias '{$value->getName()}' has no entity name — this should have been caught by the semantic analyser.");
			}
			
			// Resolve entity to fully namespaced
			$entity = $this->entityStore->resolveProxyClass($entityName);
			
			// Extract and validate range
			$rangeName = $expression->getRange()?->getName();
			
			if ($rangeName === null) {
				throw new \LogicException("Entity alias '{$value->getName()}' has no range name — this should have been caught by the semantic analyser.");
			}
			
			// Remove the range prefix from column names so they match the entity's property map.
			// E.g. "p.name" becomes "name".
			$filteredRow = $this->removeRangeFromRow($rangeName, $filteredRow);
			
			// Extract only the primary key values to look up an existing entity instance
			$primaryKeyValues = array_intersect_key($filteredRow, $relationCache['identifiers_flipped']);
			$existingEntity = $this->unitOfWork->findEntity($entity, $primaryKeyValues);
			
			if ($existingEntity !== null) {
				// A lazy-loading proxy may already be registered in the UnitOfWork.
				// Populate it now that the real data is available.
				if ($existingEntity instanceof ProxyInterface && !$existingEntity->isInitialized()) {
					$this->initializeProxy($existingEntity, $filteredRow);
				}
				
				// Mark the entity as "existing" in the Unit of Work so it is tracked
				// for changes but not queued for INSERT
				$this->unitOfWork->persistExisting($existingEntity);
				$this->enrichEntityFromJsonSources($existingEntity, $entityName, $fullRow, $jsonRangeNames);
				return $existingEntity;
			}
			
			// No existing entity found — create a new one and populate it from the row
			$newEntity = new $entity;
			$this->serializer->deserialize($newEntity, $filteredRow);
			$this->unitOfWork->persistExisting($newEntity);
			$this->enrichEntityFromJsonSources($newEntity, $entityName, $fullRow, $jsonRangeNames);
			return $newEntity;
		}
		
		/**
		 * Populates a lazy-loading proxy with real data and detaches it from the
		 * UnitOfWork so it can be re-registered as an existing (non-new) entity.
		 *
		 * @param ProxyInterface $proxy
		 * @param array<string, mixed> $data
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function initializeProxy(ProxyInterface $proxy, array $data): void {
			// Mark the proxy as initialized so it knows it has been loaded
			$proxy->setInitialized();
			
			// Deserialize the provided data into the proxy entity
			$this->serializer->deserialize($proxy, $data);
			
			// Detach so the proxy can be re-attached as an existing entity rather
			// than being treated as new and queued for INSERT.
			$this->unitOfWork->detach($proxy);
		}
		
		/**
		 * Writes values from JSON source ranges onto an entity's @SourceField properties.
		 *
		 * Called after an entity is resolved or created. For each property annotated
		 * with @SourceField, the method determines which JSON range to read from
		 * (explicit or inferred), then sets the property value directly via
		 * PropertyHandler — bypassing any setter so the entity stays passive.
		 *
		 * The method is a no-op when:
		 *  - The entity declares no @SourceField properties.
		 *  - The resolved range is not present in this row.
		 *  - The field key does not exist in the JSON range's data.
		 *
		 * @param object $entity
		 * @param string $entityName Fully qualified class name.
		 * @param array<string, mixed> $fullRow Complete merged row with prefixed keys (e.g. "json.field").
		 * @param array<string, true> $jsonRangeNames All JSON range names declared in the AST.
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function enrichEntityFromJsonSources(object $entity, string $entityName, array $fullRow, array $jsonRangeNames): void {
			// Collect @SourceField annotations for this entity class, keyed by property name
			$metadata = $this->entityStore->getMetadata($entityName);
			$jsonFieldAnnotations = $metadata->getAnnotationsOfType(SourceField::class);
			
			// Nothing to do when the entity declares no @SourceField properties
			if (empty($jsonFieldAnnotations)) {
				return;
			}
			
			// Determine which JSON ranges actually appear in this row by scanning
			// row keys for known range prefixes. This drives ambiguity detection in
			// resolveJsonRange() when no explicit range is declared on the annotation.
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
					$rangeName = $this->resolveJsonRange($annotation, $presentJsonRanges);
					
					// No usable range — skip this property
					if ($rangeName === null) {
						continue;
					}
					
					// The annotation names a range that did not appear in this row —
					// valid for optional joins, nothing to write.
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
		 * Resolves which JSON range a @SourceField annotation should read from.
		 *
		 * Resolution rules:
		 *  - Explicit range on the annotation → return it directly; the caller handles
		 *    "not present in this row" as a no-op.
		 *  - No explicit range, one JSON range present in the row → infer it automatically.
		 *  - No explicit range, no JSON ranges in the row → return null (no-op).
		 *  - No explicit range, multiple JSON ranges in the row → ambiguous; the semantic
		 *    analyser should have caught this, so throw rather than guess.
		 *
		 * @param SourceField $annotation
		 * @param array<string, true> $presentJsonRanges JSON ranges that appear as prefixes in the current row.
		 * @return string|null Resolved range name, or null when no JSON data is available.
		 */
		private function resolveJsonRange(SourceField $annotation, array $presentJsonRanges): ?string {
			// Explicit range declared on the annotation — use it as-is.
			// If that range is not present in the row the caller applies no-op logic.
			$explicitRange = $annotation->getRange();
			
			if ($explicitRange !== null) {
				return $explicitRange;
			}
			
			// No explicit range: infer from the JSON ranges present in this row
			$count = count($presentJsonRanges);
			
			// No JSON data in this row at all — nothing to enrich from
			if ($count === 0) {
				return null;
			}
			
			// Exactly one JSON range: safe to infer automatically
			if ($count === 1) {
				return array_key_first($presentJsonRanges);
			}
			
			// Multiple JSON ranges present but no explicit range on the annotation.
			// The semantic analyser enforces that @SourceField specifies a range when
			// more than one JSON source is in scope, so this path should be unreachable.
			throw new \LogicException(
				"Ambiguous @SourceField on '{$annotation->getField()}': multiple JSON ranges are present " .
				"(" . implode(', ', array_keys($presentJsonRanges)) . ") but no explicit range was declared on the annotation."
			);
		}
		
		/**
		 * Processes a non-entity value from the query result.
		 *
		 * Handles JSON source ranges, scalar property paths, and raw scalars.
		 * Mapped entity aliases are dispatched directly from processRow() and never
		 * reach this method.
		 *
		 * @param AstAlias $value
		 * @param array<string, mixed> $row
		 * @param array<string, mixed> $fullRow
		 * @param array<string, true> $jsonRangeNames
		 * @return mixed
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		private function processValue(AstAlias $value, array $row, array $fullRow, array $jsonRangeNames): mixed {
			$node = $value->getExpression();
			
			// A cast expression applies a PHP type coercion after reading the raw
			// scalar. The database already applied CAST() in SQL, but we honour the
			// requested PHP type here too so that the returned value is exactly the
			// type the caller requested (e.g. int, float, string).
			if ($node instanceof AstCast) {
				$rawValue = $row[$value->getName()] ?? null;

				if ($rawValue === null) {
					return null;
				}
				
				// is_scalar() narrows $rawValue from mixed to scalar (bool|int|float|string),
				// which is accepted by intval/floatval/strval. A non-scalar value from the
				// database (e.g. an array from a JSON column) cannot be meaningfully cast
				// to a primitive type, so we return it as-is and let the caller handle it.
				if (!is_scalar($rawValue)) {
					return $rawValue;
				}
				
				/** @noinspection PhpDuplicateMatchArmBodyInspection */
				return match ($node->getCastType()) {
					'int'     => intval($rawValue),
					'float'   => floatval($rawValue),
					'string'  => strval($rawValue),
					'bool'    => (bool) $rawValue,
					'decimal' => floatval($rawValue),
					default   => $rawValue,
				};
			}

			// Top-level identifier with no chained property — either a JSON source
			// range or a subquery (derived-table) range. Mapped entity aliases are
			// handled before this call, so neither reaches here.
			if ($node instanceof AstIdentifier && !$node->hasNext() && !$node->hasParentIdentifier()) {
				if ($node->getRange() instanceof AstRangeJsonSource) {
					return $this->processJsonAllValue($value, $row);
				}
				
				// Subquery range — no entity metadata, return the scalar directly.
				return $row[$value->getName()] ?? null;
			}
			
			// Chained property (e.g. p.name). Subquery range properties have no
			// entity metadata and are returned as raw scalars.
			if ($node instanceof AstIdentifier && $node->hasNext()) {
				if ($node->getRange()?->getEntityName() === null) {
					return $row[$value->getName()] ?? null;
				}
				
				return $this->processPropertyValue($row[$value->getName()] ?? null, $node);
			}
			
			// Any other expression (aggregate, function call, etc.) is a scalar.
			return $row[$value->getName()] ?? null;
		}
		
		/**
		 * Returns all values from a JSON source range as an unprefixed key-value array.
		 * @param AstAlias $value
		 * @param array<string, mixed> $row
		 * @return array<string, mixed>
		 */
		private function processJsonAllValue(AstAlias $value, array $row): array {
			return $this->removeRangeFromRow($value->getName(), $row);
		}
		
		/**
		 * Casts a raw database column value to its proper PHP type using the
		 * @Column annotation declared on the entity property.
		 * @param mixed $rawValue
		 * @param AstIdentifier $node
		 * @return mixed
		 * @throws HydrationException
		 * @throws EntityResolutionException
		 */
		private function processPropertyValue(mixed $rawValue, AstIdentifier $node): mixed {
			// No value. Return null and be done with it
			if ($rawValue === null) {
				return null;
			}
			
			// Fetch the entity from the node
			$entityName = $node->getEntityName();
			
			// No entity. This is bad. Throw exception.
			if ($entityName === null) {
				throw new HydrationException("Missing entity name in the AstIdentifier");
			}
			
			// Get the property name from the next node in the chain
			$propertyName = $node->getNext()?->getName();
			
			if ($propertyName === null) {
				throw new HydrationException("Missing property name in the AstIdentifier");
			}
			
			// If the chain extends beyond the column node (e.g. x.testJSON.test),
			// the SQL has already extracted the scalar via JSON path — return it as-is.
			// Running normalizeValue on it would attempt json_decode() and produce null.
			if ($node->getNext()->getNext() !== null) {
				return $rawValue;
			}
			
			// Find the @Column annotation and use it to cast the raw database value
			// to its proper PHP type
			$metadata = $this->entityStore->getMetadata($entityName);
			$annotations = $metadata->getAnnotations();
			
			foreach ($annotations[$propertyName] ?? [] as $annotation) {
				if (!$annotation instanceof Column) {
					continue;
				}
				
				return $this->unitOfWork->getSerializer()->normalizeValue($annotation, $rawValue);
			}
			
			// No column annotation. Should never happen. Semantic analyzer already detected it.
			throw new HydrationException("No @Column annotation found for property '{$propertyName}' on '{$entityName}'");
		}
		
		// =========================================================================
		// Utilities
		// =========================================================================
		
		/**
		 * Returns true if the array contains at least one non-null value.
		 * Used to detect all-null rows produced by LEFT JOIN misses.
		 *
		 * @param array<string|int, mixed> $array
		 * @return bool
		 */
		private function isArrayPopulated(array $array): bool {
			return !empty(array_filter($array, fn($val) => $val !== null));
		}
		
		/**
		 * Strips a range prefix from all keys in an array, returning only the keys
		 * that belonged to that range with the prefix removed.
		 *
		 * E.g. ["p.id" => 1, "p.name" => "Alice", "o.id" => 9] with range "p"
		 * becomes ["id" => 1, "name" => "Alice"].
		 *
		 * @param string $range The range alias to strip (without the trailing dot).
		 * @param array<string, mixed> $array
		 * @return array<string, mixed>
		 */
		private function removeRangeFromRow(string $range, array $array): array {
			$prefix = $range . '.';
			$prefixLength = strlen($prefix);
			$result = [];
			
			foreach ($array as $key => $value) {
				if (strncmp($key, $prefix, $prefixLength) === 0) {
					$result[substr($key, $prefixLength)] = $value;
				}
			}
			
			return $result;
		}
		
	}