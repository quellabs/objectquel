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
		 * Loads all relationships for a set of entities
		 * @param array<int, object> $entities The entities to load relationships for
		 * @throws QuelException
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		public function loadRelationships(array $entities): void {
			// Set ToOne relations directly where data is present; create proxies for the rest
			$this->setupToOneRelations($entities);
			
			// Set up collections for empty InverseOf relations
			$this->setupToManyRelations($entities);
			
			// Wire hydrated related entities back into parent collections
			// for InverseOf relations that were explicitly joined in the query
			foreach ($entities as $entity) {
				$this->populateEntityJoinedRelations($entity, $entities);
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
			
			// If the value of the relation column is 0 or null, the operation does not continue.
			if (empty($relationColumnValue)) {
				return;
			}
			
			// Determine the name and property of the target entity based on the dependency.
			$targetEntityName = $dependency->getTargetEntity();
			$inversedPropertyName = $this->entityStore->resolveTargetProperty($dependency);
			
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
			$relationPropertyName = $this->entityStore->resolveTargetProperty($dependency);
			
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
			
			// Check whether $targetEntity appears in the projection AND was joined
			// via the specific $joinProperty on $currentEntity. Checking only the
			// entity name would accept a join via a different property (e.g. "editor"
			// when we are looking for "author") as a false positive.
			foreach ($this->retrieve->getRanges() as $range) {
				if (!($range instanceof AstRangeDatabase)) {
					continue;
				}
				
				// Range must resolve to the target entity
				if ($this->entityStore->normalizeEntityClass($range->getEntityName()) !== $targetEntity) {
					continue;
				}
				
				// The join condition must reference $joinProperty on $currentEntity
				if ($range->hasJoinProperty($currentEntity, $joinProperty)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Sets ToOne relationships for each entity in one pass:
		 * resolves directly from the UnitOfWork where possible, otherwise creates a proxy.
		 * @param array<int, object> $entities
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 */
		private function setupToOneRelations(array $entities): void {
			foreach ($entities as $entity) {
				$entityClass = $this->entityStore->normalizeEntityClass($entity);
				
				foreach ($this->getRelationAnnotations($entityClass) as $property => $dependencies) {
					foreach ($dependencies as $dependency) {
						// InverseOf needs a scalar inverse proxy to load the entity
						if ($dependency instanceof InverseOf && !$this->isCollectionProperty($entityClass, $property)) {
							$this->createAndSetScalarInverseOfProxy($entity, $entityClass, $property, $dependency);
							continue;
						}
						
						// Other dependencies load by primary key
						if ($dependency instanceof OneToOne || $dependency instanceof ManyToOne) {
							$this->processEntityDependency($entity, $property, $dependency);
							
							if ($this->propertyHandler->get($entity, $property) === null) {
								$this->createAndSetProxy($entity, $property, $dependency);
							}
						}
					}
				}
			}
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
		private function createAndSetScalarInverseOfProxy(object $entity, string $entityClass, string $property, InverseOf $dependency): void {
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
			$resolvedTarget = $viaRelation !== null ? $this->entityStore->resolveTargetProperty($viaRelation) : null;
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
		 * Promotes empty InverseOf relationships to lazy-loaded collections for the given filtered rows.
		 * @param array<int, object> $filteredRows The rows that need to be processed
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function setupToManyRelations(array $filteredRows): void {
			// Loop through all filtered rows
			foreach ($filteredRows as $entity) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->normalizeEntityClass($entity);
				
				// Get all dependencies of the entity class
				$entityDependencies = $this->getRelationAnnotations($objectClass);
				
				// Loop through all properties and their dependencies
				foreach ($entityDependencies as $property => $dependencies) {
					foreach ($dependencies as $dependency) {
						// We only care about InverseOf here
						if (!($dependency instanceof InverseOf)) {
							continue;
						}
						
						// Fetch the property
						$propertyValue = $this->propertyHandler->get($entity, $property);
						
						// Only process properties that currently hold an empty collection
						if (!($propertyValue instanceof CollectionInterface) || !$propertyValue->isEmpty()) {
							continue;
						}
						
						// Complete short entity names to their full namespace form
						$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
						
						// Check if InverseOf has via. If not error out
						$relation = $dependency->getRelation();
						
						// Do nothing if the data for this query was requested. There is simply no data,
						// so there's no point in lazy loading this data. We keep the empty collection.
						if ($this->wasEntityRequested($objectClass, $targetEntity, $relation)) {
							continue;
						}
						
						// Resolve which property on the current entity the candidate's via FK points to
						$parentProperty = $this->resolveInverseOfParentProperty($targetEntity, $relation, $objectClass);
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
				}
			}
		}
		
		/**
		 * Populates all joined InverseOf collections on a single entity.
		 * @param object $entity The parent entity whose collections need populating
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function populateEntityJoinedRelations(object $entity, array $entities): void {
			// Resolve the real class name in case this is a proxy object
			$objectClass = $this->entityStore->normalizeEntityClass($entity);
			
			foreach ($this->getRelationAnnotations($objectClass) as $property => $dependencies) {
				foreach ($dependencies as $dependency) {
					// We only care about InverseOf here; OneToOne and ManyToOne
					// are handled by setupToOneRelations
					if (!($dependency instanceof InverseOf)) {
						continue;
					}
					
					if ($this->isCollectionProperty($objectClass, $property)) {
						$this->populateInverseOfRelation($entity, $objectClass, $property, $dependency, $entities);
					} else {
						$this->populateScalarInverseOfRelation($entity, $objectClass, $property, $dependency, $entities);
					}
				}
			}
		}
		
		/**
		 * Populates a single joined InverseOf collection on an entity from the hydrated entity set.
		 * Skips the relation if it was not explicitly joined in the query or has no valid relation column.
		 * @param object $entity The parent entity
		 * @param string $objectClass The resolved class name of the parent entity
		 * @param string $property The collection property name
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function populateInverseOfRelation(
			object $entity,
			string $objectClass,
			string $property,
			InverseOf $dependency,
			array $entities
		): void {
			// Fetch via
			$relation = $dependency->getRelation();
			
			// Resolve the full class name of the related entity
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			
			// Only handle relations where the related entity was explicitly
			// joined in the query. If it wasn't requested, setupToManyRelations
			// already set up an EntityCollection for lazy loading instead.
			if (!$this->wasEntityRequested($objectClass, $targetEntity, $relation)) {
				return;
			}
			
			// Resolve which property on the current entity the candidate's via FK points to
			$parentProperty = $this->resolveInverseOfParentProperty($targetEntity, $relation, $objectClass);
			$parentKeyValue = $this->propertyHandler->get($entity, $parentProperty);
			$collection = $this->propertyHandler->get($entity, $property);
			
			// Scan all hydrated entities in the result for candidates that
			// belong to this parent via the foreign key (via property)
			foreach ($entities as $candidate) {
				// Skip entities that are not of the expected related type
				if (!($candidate instanceof $targetEntity)) {
					continue;
				}
				
				// Verify that the candidate's via property is actually
				// annotated as a relation pointing back to this parent entity type.
				// This prevents false matches in schemas where two unrelated entity
				// types share the same FK property name and overlapping ID values.
				if (!$this->candidateMapsToParent($candidate, $relation, $objectClass)) {
					continue;
				}
				
				// Compare the candidate's FK column value against the parent's referenced property value.
				// $relation is the relation property (e.g. "user"), but we need the underlying FK column
				// value (e.g. "userId") since the relation property holds an object, not a scalar.
				if ($this->getCandidateFkValue($candidate, $relation) !== $parentKeyValue) {
					continue;
				}
				
				// Add the candidate to the parent's collection, but only if
				// it isn't already present — avoids duplicates when the same
				// result set is processed more than once
				if ($collection instanceof CollectionInterface && !$collection->contains($candidate)) {
					$collection->add($candidate);
				}
			}
		}
		
		/**
		 * Populates a scalar (single entity) InverseOf property from the hydrated entity set.
		 * Used for non-owning OneToOne style relations where the property holds a single entity
		 * rather than a collection.
		 * @param object $entity The parent entity
		 * @param string $objectClass The resolved class name of the parent entity
		 * @param string $property The scalar property name
		 * @param InverseOf $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function populateScalarInverseOfRelation(
			object $entity,
			string $objectClass,
			string $property,
			InverseOf $dependency,
			array $entities
		): void {
			// Fetch the relation
			$relation = $dependency->getRelation();
			
			// Fetch the target entity and resolve to normal entity if it's a proxy
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			
			// Only handle if the related entity was explicitly joined in the query
			if (!$this->wasEntityRequested($objectClass, $targetEntity, $relation)) {
				return;
			}
			
			// Resolve which property on the current entity the candidate's via FK points to
			$parentProperty = $this->resolveInverseOfParentProperty($targetEntity, $relation, $objectClass);
			$parentKeyValue = $this->propertyHandler->get($entity, $parentProperty);
			
			// Find the first matching candidate and set it directly on the property
			foreach ($entities as $candidate) {
				if (!($candidate instanceof $targetEntity)) {
					continue;
				}
				
				if (!$this->candidateMapsToParent($candidate, $relation, $objectClass)) {
					continue;
				}
				
				if ($this->getCandidateFkValue($candidate, $relation) !== $parentKeyValue) {
					continue;
				}
				
				$this->propertyHandler->set($entity, $property, $candidate);
				return;
			}
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
			$resolvedTarget = $this->entityStore->resolveTargetProperty($viaRelation);
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
	}