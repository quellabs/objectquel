<?php
	
	namespace Quellabs\ObjectQuel\Execution\Hydration;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Exception\HydrationException;
	use Quellabs\ObjectQuel\Collections\EntityCollection;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	
	/**
	 * @phpstan-type InverseFkIndex array<string, array<string, array<int, object>>>
	 */
	class RelationshipLoader {
		
		private AstRetrieve $retrieve;
		private UnitOfWork $unitOfWork;
		private EntityManager $entityManager;
		private EntityStore $entityStore;
		private PropertyHandler $propertyHandler;
		
		/**
		 * RelationshipLoader constructor
		 * @param EntityManager $entityManager
		 * @param AstRetrieve $retrieve
		 */
		public function __construct(EntityManager $entityManager, AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
			$this->entityManager = $entityManager;
			$this->unitOfWork = $entityManager->getUnitOfWork();
			$this->entityStore = $entityManager->getEntityStore();
			$this->propertyHandler = $entityManager->getPropertyHandler();
		}
		
		/**
		 * Loads all relationships for a set of entities.
		 *
		 * Each relation is classified along three axes and dispatched through the matrix below.
		 * Owning relations (ManyToOne / OneToOne) are handled first; the remaining InverseOf
		 * relations are selected by (collection?, joined?):
		 *
		 *   relation type        | collection? | joined? | strategy
		 *   ---------------------|-------------|---------|------------------------------------------
		 *   ManyToOne / OneToOne | n/a         | n/a     | resolve from UnitOfWork, else lazy proxy
		 *   InverseOf            | collection  | yes     | populate from the hydrated result set
		 *   InverseOf            | collection  | no      | install a lazy EntityCollection
		 *   InverseOf            | scalar      | yes     | set from result set, else lazy proxy
		 *   InverseOf            | scalar      | no      | install a lazy proxy
		 *
		 * A single pass is correct because every strategy reads only data that is already settled
		 * before this method runs — the entity's own hydrated scalar columns (FK/PK), the UnitOfWork
		 * (fully populated during hydration) and entity metadata. No strategy reads another entity's
		 * resolved relation object, so processing order does not affect the result.
		 * @param array<int, object> $entities The entities to load relationships for
		 * @throws QuelException
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		public function loadRelationships(array $entities): void {
			// Per-call FK index for eager InverseOf population. Built lazily by findInverseMatches()
			// and threaded into the eager loaders by reference; discarded when this method returns.
			/** @var InverseFkIndex $index */
			$index = [];
			
			foreach ($entities as $entity) {
				$entityClass = $this->entityStore->normalizeEntityClass($entity);
				
				foreach ($this->getRelationAnnotations($entityClass) as $property => $dependencies) {
					foreach ($dependencies as $dependency) {
						switch (true) {
							case $dependency instanceof ManyToOne:
							case $dependency instanceof OneToOne:
								$this->loadOwningRelation($entity, $property, $dependency);
								break;
							
							default: // InverseOf — the only remaining annotation type
								$relation = $dependency->getRelation();
								$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
								$isJoined = $this->wasEntityRequested($entityClass, $targetEntity, $relation);
								$isCollection = $this->isCollectionProperty($entityClass, $property);
								
								match (true) {
									$isCollection && $isJoined => $this->loadInverseCollectionEager($entity, $entityClass, $property, $dependency, $entities, $index),
									$isCollection => $this->loadInverseCollectionLazy($entity, $entityClass, $property, $dependency),
									$isJoined => $this->loadInverseScalarEager($entity, $entityClass, $property, $dependency, $entities, $index),
									default => $this->loadInverseScalarLazy($entity, $entityClass, $property, $dependency),
								};
								
								break;
						}
					}
				}
			}
		}
		
		/**
		 * Processes the dependency of an entity and updates the property with the specified dependency.
		 * This function checks if the current relation is null or not initialized and then searches
		 * for the related entity based on the given dependency. If a matching entity
		 * is found, the property of the current entity is updated to reflect this relationship.
		 * @param object $entity The entity whose dependency is being processed.
		 * @param string $property The property of the entity that needs to be updated.
		 * @param ManyToOne|OneToOne $dependency The dependency used to find the related entity.
		 * @throws EntityResolutionException
		 */
		private function processEntityDependency(object $entity, string $property, ManyToOne|OneToOne $dependency): void {
			// Get the current value of the property.
			$currentRelation = $this->propertyHandler->get($entity, $property);
			
			// Check if the current relation is already set and if it's not an uninitialized proxy.
			if ($currentRelation !== null &&
				(!($currentRelation instanceof ProxyInterface) || $currentRelation->isInitialized())) {
				return;
			}
			
			// Determine the column and value for the relation based on the dependency.
			$relationColumn = $dependency->getLocalColumn() ?? "{$property}Id";
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			// A null relation column value means there is no relation to resolve.
			if ($relationColumnValue === null) {
				return;
			}
			
			// Determine the name and property of the target entity based on the dependency.
			$targetEntityName = $dependency->getTargetEntity();
			$inversedPropertyName = $this->resolveTargetProperty($dependency);
			
			if ($inversedPropertyName === null) {
				throw new \LogicException(
					"Cannot resolve target property for '{$property}': target entity '{$targetEntityName}' " .
					"has no primary key. Ensure the entity is correctly annotated."
				);
			}
			
			// Add the namespace to the target entity name and find the related entity.
			$targetEntity = $this->entityStore->normalizeEntityClass($targetEntityName);
			
			// Check the UnitOfWork if it has the entity
			$relationEntity = $this->unitOfWork->findEntity($targetEntity, [$inversedPropertyName => $relationColumnValue]);
			
			// If a related entity is found, update the property of the current entity.
			if ($relationEntity !== null) {
				// Update the property with the found entity
				// If a setter method exists, execute it.
				// Otherwise set the property directly.
				$setterMethod = 'set' . ucfirst($property);
				
				if (method_exists($entity, $setterMethod)) {
					$entity->{$setterMethod}($relationEntity);
				} else {
					$this->propertyHandler->set($entity, $property, $relationEntity);
				}
			}
		}
		
		/**
		 * Creates a proxy object for a given dependency and sets it on the entity.
		 * This method handles lazy loading of relationships by creating proxy instances
		 * that will load their data only when accessed.
		 * @param object $entity The entity on which to set the proxy
		 * @param string $property The name of the property where the proxy will be set
		 * @param ManyToOne|OneToOne $dependency
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		private function createAndSetProxy(object $entity, string $property, ManyToOne|OneToOne $dependency): void {
			// Determine the relation column (the column containing the foreign key)
			$relationColumn = $dependency->getLocalColumn() ?? "{$property}Id";
			
			// Get the primary key value. If it's empty, clear the relationship
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			if ($relationColumnValue === null) {
				$this->propertyHandler->set($entity, $property, null);
				return;
			}
			
			// Validate relationColumnValue
			if (!is_integer($relationColumnValue) && !is_string($relationColumnValue)) {
				throw new HydrationException("Cannot load proxy when lookup key is not int or string");
			}
			
			// Gather information needed to create the proxy.
			// normalizeEntityClass ensures we have the fully-qualified class name.
			$targetEntityName = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			
			// resolveTargetProperty returns null only when the target entity has no
			// primary key — a configuration error that should have been caught earlier.
			$relationPropertyName = $this->resolveTargetProperty($dependency);
			
			// Error when entity has no primary key
			if ($relationPropertyName === null) {
				throw new \LogicException(
					"Cannot build proxy for '{$property}': target entity '{$dependency->getTargetEntity()}' " .
					"has no primary key. Ensure the entity is correctly annotated."
				);
			}
			
			// Create the proxy
			$proxy = $this->findOrCreateProxy($targetEntityName, $relationPropertyName, $relationColumnValue);
			
			// Set the proxy on the original entity
			$this->propertyHandler->set($entity, $property, $proxy);
		}
		
		/**
		 * Returns an existing entity from the UnitOfWork, or instantiates and persists a new proxy.
		 * @param class-string $targetEntityName
		 * @param string $relationPropertyName The property on the target that holds the PK
		 * @param int|string $relationColumnValue
		 * @return object
		 * @throws EntityResolutionException
		 */
		private function findOrCreateProxy(
			string $targetEntityName,
			string $relationPropertyName,
			int|string $relationColumnValue
		): object {
			// Check if the entity already exists in the UnitOfWork
			$existing = $this->unitOfWork->findEntity($targetEntityName, [
				$relationPropertyName => $relationColumnValue
			]);
			
			// If the entity already is in UnitOfWork, return it
			if ($existing !== null) {
				return $existing;
			}
			
			// Create a new proxy if no existing entity was found
			$generator = $this->entityStore->getProxyGenerator();
			$proxyClassName = $generator->getProxyClass($targetEntityName);
			
			// Load the proxy class file if it doesn't exist
			if (!class_exists($proxyClassName, false)) {
				require_once $generator->getProxyFilePath($targetEntityName);
			}
			
			// Build the PK-based initializer: seeds this proxy's PK then lets find() hydrate it
			$entityManager = $this->entityManager;
			$initializer = function () use ($entityManager, $targetEntityName, $relationColumnValue): void {
				$entityManager->find($targetEntityName, $relationColumnValue);
			};
			
			// Instantiate the proxy with the explicit initializer
			$proxy = new $proxyClassName($this->entityManager, $initializer);
			
			// Set the primary key on the proxy using the target entity's primary key property name
			$metadata = $this->entityStore->getMetadata($targetEntityName);
			$this->propertyHandler->set($proxy, $metadata->identifierKeys[0], $relationColumnValue);
			
			// Put the proxy under ownership
			$this->entityManager->persist($proxy);
			
			// Return the proxy
			return $proxy;
		}
		
		/**
		 * Checks if a specific entity type was requested via a specific join property.
		 * @param string $currentEntity
		 * @param string $targetEntity The entity class name
		 * @param string $joinProperty The specific join property we are looking for
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function wasEntityRequested(string $currentEntity, string $targetEntity, string $joinProperty): bool {
			// Avoid false positives on self-referencing entities
			if ($currentEntity === $targetEntity) {
				return false;
			}
			
			// The via-rewrite tags the FK-holding (dependent) range with its owning-side relation
			// property name. That range's entity is exactly $targetEntity and $joinProperty is a
			// relation declared on it, so matching (entity, viaRelation) is unambiguous: a relation
			// of the same name on a different entity (e.g. "editor" vs "author") cannot collide.
			foreach ($this->retrieve->getRanges() as $range) {
				if (!($range instanceof AstRangeDatabase)) {
					continue;
				}
				
				// Range must have been joined via this exact relation. Require the join to still be
				// present: optimizer passes can fold a join into WHERE and null it, in which case the
				// relation was not materialised as a join and we fall back to lazy loading.
				if ($range->getJoinProperty() === null || $range->getViaRelation() !== $joinProperty) {
					continue;
				}
				
				// ...and resolve to the target entity
				if ($this->entityStore->normalizeEntityClass($range->getEntityName()) === $targetEntity) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Creates a lazy-loading proxy for a scalar InverseOf property and sets it on the entity.
		 * The FK lives on the dependent entity so the proxy uses a custom initializer that
		 * resolves via findOneBy rather than a direct PK-based find.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $property The scalar property name
		 * @param InverseOf $dependency The InverseOf annotation
		 * @throws EntityResolutionException
		 */
		private function loadInverseScalarLazy(object $entity, string $entityClass, string $property, InverseOf $dependency): void {
			// The via property name on the dependent entity — the FK that points back to this entity
			$relation = $dependency->getRelation();
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			
			// Look up the relation annotation on the dependent entity's via property so we can
			// determine which property on *this* entity the FK references (usually the PK,
			// but may be a unique non-PK column if inversedBy says otherwise)
			$dependentMetadata = $this->entityStore->getMetadata($targetEntity);
			$viaRelation = $this->getRelationAnnotation($dependentMetadata, $relation);
			
			// Resolve the referenced property on this entity; fall back to primary key when
			// inversedBy is absent or the relation annotation cannot be found
			$ownerPrimaryKey = $this->entityStore->getMetadata($entityClass)->getPrimaryKey();
			$resolvedTarget = $viaRelation !== null ? $this->resolveTargetProperty($viaRelation) : null;
			$parentProperty = $resolvedTarget ?? $ownerPrimaryKey;
			
			if ($parentProperty === null) {
				throw new \RuntimeException(
					"Cannot resolve parent property for InverseOf on {$entityClass}::{$property}: entity has no primary key."
				);
			}
			
			// Read the value of the referenced property from this entity instance
			$parentKeyValue = $this->propertyHandler->get($entity, $parentProperty);
			
			// Load the proxy class file if needed
			$generator = $this->entityStore->getProxyGenerator();
			$proxyClassName = $generator->getProxyClass($targetEntity);
			
			if (!class_exists($proxyClassName, false)) {
				require_once $generator->getProxyFilePath($targetEntity);
			}
			
			// Capture references for the initializer closure
			$entityManager = $this->entityManager;
			$propertyHandler = $this->propertyHandler;
			
			$proxy = new $proxyClassName(
				$this->entityManager,
				function () use ($entityManager, $propertyHandler, $entity, $property, $targetEntity, $relation, $parentKeyValue): void {
					// findOneBy returns a fully hydrated entity registered in the UnitOfWork.
					$result = $entityManager->findOneBy($targetEntity, [$relation => $parentKeyValue]);
					
					// Set it directly on the parent property — no proxy seeding needed.
					$propertyHandler->set($entity, $property, $result);
				}
			);
			
			// Set proxy on the entity
			$this->propertyHandler->set($entity, $property, $proxy);
		}
		
		/**
		 * Scalar InverseOf joined cell: sets the related entity from the hydrated result set, or
		 * installs a lazy proxy when no row in the result matched.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $property The scalar property name
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @param InverseFkIndex $index Per-call FK bucket index, threaded by reference (see findInverseMatches())
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function loadInverseScalarEager(
			object $entity,
			string $entityClass,
			string $property,
			InverseOf $dependency,
			array $entities,
			array &$index
		): void {
			if (!$this->tryAssignInverseScalarFromResultSet($entity, $entityClass, $property, $dependency, $entities, $index)) {
				$this->loadInverseScalarLazy($entity, $entityClass, $property, $dependency);
			}
		}
		
		/**
		 * Loads an owning-side ManyToOne or OneToOne relation: resolves the related entity directly
		 * from the UnitOfWork when present, otherwise installs a lazy proxy keyed on the FK column.
		 * @param object $entity
		 * @param string $property
		 * @param ManyToOne|OneToOne $dependency
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		private function loadOwningRelation(object $entity, string $property, ManyToOne|OneToOne $dependency): void {
			// Resolve directly from the UnitOfWork where the related entity is already loaded
			$this->processEntityDependency($entity, $property, $dependency);
			
			// Nothing resolved — fall back to a lazy proxy loaded by primary key
			if ($this->propertyHandler->get($entity, $property) === null) {
				$this->createAndSetProxy($entity, $property, $dependency);
			}
		}
		
		/**
		 * Populates a joined InverseOf collection from the hydrated entity set: every entity in the
		 * result that points back to $entity through the via FK is added to the collection.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $property The collection property name
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @param InverseFkIndex $index Per-call FK bucket index, threaded by reference (see findInverseMatches())
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws \LogicException When the property is not a CollectionInterface
		 */
		private function loadInverseCollectionEager(
			object $entity,
			string $entityClass,
			string $property,
			InverseOf $dependency,
			array $entities,
			array &$index
		): void {
			// The collection is created by the entity itself and must be a CollectionInterface.
			// A plain array (or any non-collection) cannot hold managed relations, so fail loudly
			// rather than silently dropping the inverse population.
			$collection = $this->propertyHandler->get($entity, $property);
			
			if (!($collection instanceof CollectionInterface)) {
				throw new \LogicException(
					"InverseOf collection '{$entityClass}::{$property}' must be typed as " .
					CollectionInterface::class . " and initialized to a collection instance; got " .
					get_debug_type($collection) . ". Declare it as 'public CollectionInterface \${$property};' " .
					"and initialize it in the entity constructor."
				);
			}
			
			// Resolve the candidates belonging to this parent via the shared FK index (one indexed
			// pass over the result set, not a per-parent linear scan).
			foreach ($this->findInverseMatches($entity, $entityClass, $dependency, $entities, $index) as $candidate) {
				// Add the candidate to the parent's collection, but only if
				// it isn't already present — avoids duplicates when the same
				// result set is processed more than once
				if (!$collection->contains($candidate)) {
					$collection->add($candidate);
				}
			}
		}
		
		/**
		 * Populates a joined scalar InverseOf property from the hydrated entity set.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $property The scalar property name
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @param InverseFkIndex $index Per-call FK bucket index, threaded by reference (see findInverseMatches())
		 * @return bool True when a matching entity was found and set, false otherwise
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function tryAssignInverseScalarFromResultSet(
			object $entity,
			string $entityClass,
			string $property,
			InverseOf $dependency,
			array $entities,
			array &$index
		): bool {
			// Find the first matching candidate via the shared FK index and set it on the property
			foreach ($this->findInverseMatches($entity, $entityClass, $dependency, $entities, $index) as $candidate) {
				$this->propertyHandler->set($entity, $property, $candidate);
				return true;
			}
			
			return false;
		}
		
		/**
		 * Installs a lazy-loaded EntityCollection for an InverseOf collection that was not joined.
		 * The collection fetches its members on first access using the dependent entity's FK column.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $property The collection property name
		 * @param InverseOf $dependency The relation annotation
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws \LogicException When the property is not a CollectionInterface
		 */
		private function loadInverseCollectionLazy(
			object $entity,
			string $entityClass,
			string $property,
			InverseOf $dependency
		): void {
			// Fetch the property
			$propertyValue = $this->propertyHandler->get($entity, $property);
			
			// InverseOf collections must be typed as a CollectionInterface — the scaffolder emits this
			// and initializes them in the constructor. A plain array cannot be lazily managed, so fail
			// loudly instead of silently skipping.
			if (!($propertyValue instanceof CollectionInterface)) {
				throw new \LogicException(
					"InverseOf collection '{$entityClass}::{$property}' must be typed as " .
					CollectionInterface::class . " and initialized to a collection instance; got " .
					get_debug_type($propertyValue) . ". Declare it as 'public CollectionInterface \${$property};' " .
					"and initialize it in the entity constructor."
				);
			}
			
			// Skip collections that are already populated to avoid clobbering existing members
			if (!$propertyValue->isEmpty()) {
				return;
			}
			
			// Complete short entity names to their full namespace form
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			
			// Check if InverseOf has via. If not error out
			$relation = $dependency->getRelation();
			
			// Resolve which property on the current entity the candidate's via FK points to
			$parentProperty = $this->resolveInverseOfParentProperty($targetEntity, $relation, $entityClass);
			$primaryKeyValue = $this->propertyHandler->get($entity, $parentProperty);
			
			// Resolve the FK column name on the dependent entity — EntityCollection queries
			// by column property (e.g. userId), not by the relation property (e.g. user),
			// since relation properties are rejected by the semantic analyser.
			$dependentMetadata = $this->entityStore->getMetadata($targetEntity);
			$viaAnnotation = $this->getRelationAnnotation($dependentMetadata, $relation);
			$fkColumn = $viaAnnotation?->getLocalColumn() ?? $relation . 'Id';
			
			// Create an Entity Collection
			$proxy = new EntityCollection($this->entityManager, $targetEntity, $fkColumn, $primaryKeyValue);
			
			// Assign it to entity
			$this->propertyHandler->set($entity, $property, $proxy);
		}
		
		/**
		 * Resolves the parent key value that an InverseOf candidate's FK must match: looks up which
		 * property on the parent the via FK references, then reads that property from the parent.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param string $relation The via relation property name on the dependent entity
		 * @param string $targetEntity The resolved dependent entity class name
		 * @return mixed The scalar value the candidate's FK is compared against
		 * @throws EntityResolutionException
		 */
		private function resolveParentKeyValue(object $entity, string $entityClass, string $relation, string $targetEntity): mixed {
			$parentProperty = $this->resolveInverseOfParentProperty($targetEntity, $relation, $entityClass);
			return $this->propertyHandler->get($entity, $parentProperty);
		}
		
		/**
		 * Returns the hydrated candidates whose via FK points back to $entity for an InverseOf
		 * relation. Replaces the previous per-parent linear scan over the full result set: the first
		 * parent needing a given (target entity, relation, declaring class) triple pays one O(n) pass
		 * to bucket every candidate by FK value; later parents reuse those buckets via an O(1) lookup.
		 * The $index is owned by loadRelationships() and threaded in by reference, so it lives only
		 * for the current call.
		 *
		 * Membership uses the same predicate the linear scan did — instanceof the target,
		 * candidateMapsToParent(), and a strict comparison of the candidate's FK value against the
		 * parent key — so behaviour and result-set ordering are preserved.
		 * @param object $entity The parent entity
		 * @param string $entityClass The resolved class name of the parent entity
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @param InverseFkIndex $index Per-call FK bucket index (indexKey => bucketKey => candidates), built lazily and reused across parents
		 * @return array<int, object> Matching candidates, in result-set order
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function findInverseMatches(object $entity, string $entityClass, InverseOf $dependency, array $entities, array &$index): array {
			$relation = $dependency->getRelation();
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			$parentKeyValue = $this->resolveParentKeyValue($entity, $entityClass, $relation, $targetEntity);
			
			// One index per (target entity, via relation, declaring class). parentClass is part of the
			// key because candidateMapsToParent() is evaluated against it, so buckets cannot be shared
			// across parents of different classes. A NUL separator cannot occur in a class or property
			// name, so the composite key is unambiguous.
			$indexKey = $targetEntity . "\0" . $relation . "\0" . $entityClass;
			
			if (!isset($index[$indexKey])) {
				$buckets = [];
				
				foreach ($entities as $candidate) {
					// Skip entities that are not of the expected related type. Checked first so unrelated
					// candidates never trigger a metadata lookup.
					if (!($candidate instanceof $targetEntity)) {
						continue;
					}
					
					// Verify that the candidate's via property is actually
					// annotated as a relation pointing back to this parent entity type.
					// This prevents false matches in schemas where two unrelated entity
					// types share the same FK property name and overlapping ID values.
					if (!$this->candidateMapsToParent($candidate, $relation, $entityClass)) {
						continue;
					}
					
					// Bucket by the candidate's FK column value. $relation is the relation property
					// (e.g. "user"), but we need the underlying FK column value (e.g. "userId") since
					// the relation property holds an object, not a scalar.
					$buckets[$this->inverseBucketKey($this->getCandidateFkValue($candidate, $relation))][] = $candidate;
				}
				
				$index[$indexKey] = $buckets;
			}
			
			return $index[$indexKey][$this->inverseBucketKey($parentKeyValue)] ?? [];
		}
		
		/**
		 * Encodes a scalar FK value into a bucket key that preserves strict (===) semantics: the
		 * type-prefixed encoding keeps int 1, string "1", null and false in separate buckets,
		 * matching the comparison the previous linear scan performed. FK values are int|string in
		 * practice (the proxy path constrains lookup keys to those types).
		 * @param mixed $value
		 * @return string
		 */
		private function inverseBucketKey(mixed $value): string {
			if (is_int($value)) {
				return 'i:' . $value;
			}
			
			if (is_string($value)) {
				return 's:' . $value;
			}
			
			if ($value === null) {
				return 'n:';
			}
			
			if (is_bool($value)) {
				return 'b:' . ($value ? '1' : '0');
			}
			
			return 'x:' . var_export($value, true);
		}
		
		/**
		 * Returns the ManyToOne or owning-side OneToOne annotation for a given property
		 * on an entity, or null if no such relation exists.
		 * Centralises the repeated pattern of checking both relation types.
		 * @param EntityMetadataRecord $metadata
		 * @param string $property
		 * @return ManyToOne|OneToOne|null
		 */
		private function getRelationAnnotation(EntityMetadataRecord $metadata, string $property): ManyToOne|OneToOne|null {
			return $metadata->getManyToOneDependencies()[$property]
				?? $metadata->getOneToOneDependencies()[$property]
				?? null;
		}
		
		/**
		 * Returns true if the candidate's specific $relation property is a ManyToOne or OneToOne
		 * that points to $parentClass.
		 * @param object $candidate
		 * @param string $relation
		 * @param string $parentClass
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function candidateMapsToParent(object $candidate, string $relation, string $parentClass): bool {
			$candidateClass = $this->entityStore->normalizeEntityClass($candidate);
			$metadata = $this->entityStore->getMetadata($candidateClass);
			
			// Look up the exact relation property — not just any relation pointing to $parentClass
			$dep = $this->getRelationAnnotation($metadata, $relation);
			
			// Bail if the relation does not exist
			if ($dep === null) {
				return false;
			}
			
			// Validate the relation points back to this entity
			return $this->entityStore->normalizeEntityClass($dep->getTargetEntity()) === $parentClass;
		}
		
		/**
		 * Resolves the property on the owner entity that an InverseOf's via FK references.
		 * Looks up the ManyToOne or OneToOne annotation on the dependent entity's via property,
		 * validates that it points back to the owner, and returns the referenced property name.
		 * @param string $targetEntity Fully qualified dependent entity class name
		 * @param string $relation Property name on the dependent entity that holds the FK
		 * @param string $ownerClass Fully qualified owner entity class name
		 * @return string The referenced property name on the owner
		 * @throws EntityResolutionException
		 * @throws \RuntimeException When via does not reference a valid back-pointing relation
		 */
		private function resolveInverseOfParentProperty(string $targetEntity, string $relation, string $ownerClass): string {
			$dependentMetadata = $this->entityStore->getMetadata($targetEntity);
			$viaRelation = $this->getRelationAnnotation($dependentMetadata, $relation);
			
			// via must reference a ManyToOne or OneToOne on the dependent entity
			if ($viaRelation === null) {
				throw new \RuntimeException(
					"InverseOf relation='{$relation}' on {$ownerClass} does not match any ManyToOne or OneToOne on {$targetEntity}."
				);
			}
			
			// The relation must point back at the declaring entity
			$resolvedEntity = $this->entityStore->normalizeEntityClass($viaRelation->getTargetEntity());
			
			if ($resolvedEntity !== $ownerClass) {
				throw new \RuntimeException(
					"InverseOf via='{$relation}' on {$ownerClass} points to {$resolvedEntity}, not {$ownerClass}. " .
					"The via property must reference a relation that points back to the declaring entity."
				);
			}
			
			// Resolve the referenced property on the owner; fall back to its primary key.
			// Both being null means the target entity has no primary key — a configuration error.
			$ownerPrimaryKey = $this->entityStore->getMetadata($ownerClass)->getPrimaryKey();
			$resolvedTarget = $this->resolveTargetProperty($viaRelation);
			$parentProperty = $resolvedTarget ?? $ownerPrimaryKey;
			
			if ($parentProperty === null) {
				throw new \RuntimeException(
					"Cannot resolve parent property for InverseOf on {$ownerClass}: owner entity '{$ownerClass}' has no primary key."
				);
			}
			
			return $parentProperty;
		}
		
		/**
		 * Resolves the FK column value on a candidate entity for a given via property.
		 * The via property is a relation object (e.g. UserEntity), not a scalar, so we
		 * read the underlying FK column (e.g. userId) instead.
		 * @param object $candidate
		 * @param string $relation The relation property name on the candidate
		 * @return mixed The scalar FK value
		 * @throws EntityResolutionException
		 */
		private function getCandidateFkValue(object $candidate, string $relation): mixed {
			// If the candidate is a proxy, convert it to regular class
			$candidateClass = $this->entityStore->normalizeEntityClass($candidate);
			
			// Fetch the metadata of the candidate entity
			$candidateMeta = $this->entityStore->getMetadata($candidateClass);
			
			// Look up the relation annotation to find the FK column name
			$viaAnnotation = $this->getRelationAnnotation($candidateMeta, $relation);
			$fkProperty = $viaAnnotation?->getLocalColumn() ?? $relation . 'Id';
			
			// Return the value of the relation column
			return $this->propertyHandler->get($candidate, $fkProperty);
		}
		
		/**
		 * Returns true if the declared PHP type of $property on $objectClass implements CollectionInterface.
		 * Used to determine whether an InverseOf annotation targets a collection or a scalar entity.
		 * @param class-string $objectClass Fully qualified class name
		 * @param string $property Property name
		 * @return bool
		 */
		private function isCollectionProperty(string $objectClass, string $property): bool {
			// Fetch property type
			$type = $this->propertyHandler->getType($objectClass, $property);
			
			// Has to be a named type
			if (!$type instanceof \ReflectionNamedType) {
				return false;
			}
			
			// Fetch the type name
			$typeName = $type->getName();
			
			// Treat array and any CollectionInterface implementation as a collection
			return $typeName === 'array' || is_a($typeName, CollectionInterface::class, true);
		}
		
		/**
		 * Internal helper function for retrieving properties with a specific annotation.
		 * Returns all relationship annotations (ManyToOne, InverseOf, OneToOne) for the entity.
		 * @param string|object $entity The name of the entity for which you want to get dependencies
		 * @return array<string, array<int, ManyToOne|OneToOne|InverseOf>> Property name => array of relationship annotations
		 * @throws EntityResolutionException
		 */
		private function getRelationAnnotations(string|object $entity): array {
			$metadata = $this->entityStore->getMetadata($entity);
			
			// Get all annotations for the entity
			$annotationList = $metadata->annotations;
			
			// Loop through each annotation to check for a relationship
			$result = [];
			
			foreach (array_keys($annotationList) as $property) {
				foreach ($annotationList[$property] as $annotation) {
					if ($annotation instanceof InverseOf || $annotation instanceof OneToOne || $annotation instanceof ManyToOne) {
						$result[$property][] = $annotation;
						continue 2;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Resolves the back-reference property name on the target entity for a ManyToOne or OneToOne relation.
		 *
		 * For OneToOne, inversedBy is the primary key property on the target entity that the
		 * foreign key column points to. If not set, the target entity's primary key is used as a fallback.
		 *
		 * For ManyToOne, inversedBy is a direct property name on the target entity. If absent,
		 * the target entity's primary key is used as a fallback.
		 *
		 * Returns null when no property can be determined.
		 *
		 * @param ManyToOne|OneToOne $relation The relation annotation to resolve
		 * @return string|null The back-reference property name on the target entity, or null if unresolvable
		 * @throws EntityResolutionException When target entity metadata cannot be loaded
		 */
		private function resolveTargetProperty(ManyToOne|OneToOne $relation): ?string {
			// Fetch metadata for entity
			$metadata = $this->entityStore->getMetadata($relation->getTargetEntity());
			
			// Return referencedColumn, falling back to the primary key
			return $relation->getReferencedColumn() ?? $metadata->getPrimaryKey();
		}
	}