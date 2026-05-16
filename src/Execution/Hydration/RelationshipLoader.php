<?php
	
	namespace Quellabs\ObjectQuel\Execution\Hydration;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	use Quellabs\ObjectQuel\Collections\EntityCollection;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
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
		 */
		public function loadRelationships(array $entities): void {
			// Set ToOne relations directly where data is present; create proxies for the rest
			$this->setupToOneRelations($entities);
			
			// Set up collections for empty OneToMany relations
			$this->setupToManyRelations($entities);
			
			// Wire hydrated related entities back into parent collections
			// for OneToMany relations that were explicitly joined in the query
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
			$relationColumn = $dependency->getRelationColumn() ?? "{$property}Id";
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			// If the value of the relation column is 0 or null, the operation does not continue.
			if (empty($relationColumnValue)) {
				return;
			}
			
			// Determine the name and property of the target entity based on the dependency.
			$targetEntityName = $dependency->getTargetEntity();
			$inversedPropertyName = $this->entityStore->resolveTargetProperty($dependency) ?? '';
			
			// Add the namespace to the target entity name and find the related entity.
			$targetEntity = $this->entityStore->resolveProxyClass($targetEntityName);
			
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
		 */
		private function createAndSetProxy(object $entity, string $property, ManyToOne|OneToOne $dependency): void {
			// Determine the relation column (the column containing the foreign key)
			$relationColumn = $dependency->getRelationColumn() ?? "{$property}Id";
			
			// Get the primary key value. If it's empty, clear the relationship
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			if (empty($relationColumnValue)) {
				$this->propertyHandler->set($entity, $property, null);
				return;
			}
			
			// Gather information needed to create the proxy.
			// resolveProxyClass ensures we have the fully-qualified class name.
			$targetEntityName = $this->entityStore->resolveProxyClass($dependency->getTargetEntity());
			
			// resolveTargetProperty handles both ManyToOne and OneToOne transparently.
			// If it returns null, the target property is unknown and we cannot build a proxy.
			$relationPropertyName = $this->entityStore->resolveTargetProperty($dependency);
			
			// Return if the property could not be resolved
			if ($relationPropertyName === null) {
				return;
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
		 * @param mixed $relationColumnValue
		 * @return object
		 * @throws EntityResolutionException
		 */
		private function findOrCreateProxy(
			string $targetEntityName,
			string $relationPropertyName,
			mixed  $relationColumnValue
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
			
			// Instantiate the proxy
			$proxy = new $proxyClassName($this->entityManager);
			
			// Set the primary key on the proxy using the target entity's primary key property name
			$pk = $this->entityStore->getIdentifierKeys($targetEntityName);
			$this->propertyHandler->set($proxy, $pk[0], $relationColumnValue);
			
			// Put the proxy under ownership
			$this->entityManager->persist($proxy);
			
			// Return the proxy
			return $proxy;
		}
		
		/**
		 * Checks if a specific entity type was requested via a specific join property.
		 * @param string $targetEntity The entity class name
		 * @param string $joinProperty The specific join property we are looking for
		 * @return bool
		 */
		private function wasEntityRequested(string $currentEntity, string $targetEntity, string $joinProperty): bool {
			// Always return false when this is a self-referencing entity
			if ($currentEntity === $targetEntity) {
				return false;
			}
			
			// Find a range that matches the relation criteria. If one is found, return true.
			foreach ($this->retrieve->getValues() as $value) {
				$expression = $value->getExpression();
				
				// Omit non entity values
				if (!($expression instanceof AstIdentifier) ||
					!($expression->getRange() instanceof AstRangeDatabase) ||
					$expression->hasNext()) {
					continue;
				}
				
				// Check if the entity matches and if the join property occurs in the range
				$range = $expression->getRange();
				
				if (
					$expression->getEntityName() === $targetEntity &&
					$range->hasJoinProperty($targetEntity, $joinProperty)
				) {
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
		 */
		private function setupToOneRelations(array $entities): void {
			foreach ($entities as $entity) {
				// Normalize the entity class name
				$entityClass = $this->entityStore->resolveProxyClass($entity);
				
				// Iterate through each property and its dependencies in the relationship cache
				foreach ($this->entityStore->getAllDependencies($entityClass) as $property => $dependencies) {
					// Iterate through each dependency of the property
					foreach ($dependencies as $dependency) {
						// Check if the dependency is a OneToOne or ManyToOne relationship
						if (!($dependency instanceof OneToOne) && !($dependency instanceof ManyToOne)) {
							continue;
						}
						
						// Try to resolve directly from already-hydrated entities
						// Process the entity dependency
						$this->processEntityDependency($entity, $property, $dependency);
						
						// If still null, create a lazy-loading proxy
						if ($this->propertyHandler->get($entity, $property) === null) {
							$this->createAndSetProxy($entity, $property, $dependency);
						}
					}
				}
			}
		}
		
		/**
		 * Promotes empty OneToMany relationships to lazy-loaded collections for the given filtered rows.
		 * @param array<int, object> $filteredRows The rows that need to be processed
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function setupToManyRelations(array $filteredRows): void {
			// Loop through all filtered rows
			foreach ($filteredRows as $entity) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->resolveProxyClass($entity);
				
				// Get all dependencies of the entity class
				$entityDependencies = $this->entityStore->getAllDependencies($objectClass);
				
				// Loop through all properties and their dependencies
				foreach ($entityDependencies as $property => $dependencies) {
					foreach ($dependencies as $dependency) {
						// We only care about OneToMany here
						if (!($dependency instanceof OneToMany)) {
							continue;
						}
						
						// Fetch the property
						$propertyValue = $this->propertyHandler->get($entity, $property);
						
						// Only process properties that currently hold an empty collection
						if (!($propertyValue instanceof Collection) || !$propertyValue->isEmpty()) {
							continue;
						}
						
						// Complete short entity names to their full namespace form
						$targetEntity = $this->entityStore->resolveProxyClass($dependency->getTargetEntity());
						
						// Fetch the relation column. If absent use the primary key
						$relationColumn = $dependency->getRelationColumn() ?? $this->entityStore->getPrimaryKey($entity);
						
						if ($relationColumn === null) {
							throw new QuelException(
								"Cannot determine relation column for OneToMany on {$objectClass}::{$property}"
							);
						}
						
						// Check if OneToMany has mappedBy. If not error out
						$mappedBy = $dependency->getMappedBy();
						
						if ($mappedBy === null || $mappedBy === '') {
							throw new QuelException(
								"OneToMany on {$objectClass}::{$property} requires mappedBy"
							);
						}
						
						// Do nothing if the data for this query was requested. There is simply no data,
						// so there's no point in lazy loading this data. We keep the empty collection.
						if ($this->wasEntityRequested($objectClass, $targetEntity, $mappedBy)) {
							continue;
						}
						
						// Fetch relation column value
						$primaryKeyValue = $this->propertyHandler->get($entity, $relationColumn);
						
						// Create an Entity Collection
						$proxy = new EntityCollection(
							$this->entityManager, $targetEntity, $mappedBy,
							$primaryKeyValue, $dependency->getOrderBy()
						);
						
						$this->propertyHandler->set($entity, $property, $proxy);
					}
				}
			}
		}
		
		/**
		 * Populates all joined OneToMany collections on a single entity.
		 * @param object $entity The parent entity whose collections need populating
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function populateEntityJoinedRelations(object $entity, array $entities): void {
			// Resolve the real class name in case this is a proxy object
			$objectClass = $this->entityStore->resolveProxyClass($entity);
			
			foreach ($this->entityStore->getAllDependencies($objectClass) as $property => $dependencies) {
				foreach ($dependencies as $dependency) {
					// We only care about OneToMany here; OneToOne and ManyToOne
					// are handled by setupToOneRelations
					if (!($dependency instanceof OneToMany)) {
						continue;
					}
					
					$this->populateOneToManyRelation($entity, $objectClass, $property, $dependency, $entities);
				}
			}
		}
		
		/**
		 * Populates a single joined OneToMany collection on an entity from the hydrated entity set.
		 * Skips the relation if it was not explicitly joined in the query or has no valid relation column.
		 * @param object $entity The parent entity
		 * @param string $objectClass The resolved class name of the parent entity
		 * @param string $property The collection property name
		 * @param OneToMany $dependency The relation annotation
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function populateOneToManyRelation(
			object    $entity,
			string    $objectClass,
			string    $property,
			OneToMany $dependency,
			array     $entities
		): void {
			// Fetch mappedBy
			$mappedBy = $dependency->getMappedBy();
			
			// If not given, skip the dependency
			if ($mappedBy === null || $mappedBy === '') {
				return;
			}
			
			// Resolve the full class name of the related entity
			$targetEntity = $this->entityStore->resolveProxyClass($dependency->getTargetEntity());
			
			// Only handle relations where the related entity was explicitly
			// joined in the query. If it wasn't requested, setupToManyRelations
			// already set up an EntityCollection for lazy loading instead.
			if (!$this->wasEntityRequested($objectClass, $targetEntity, $mappedBy)) {
				return;
			}
			
			// Determine which property on this entity holds its primary key value.
			// The OneToMany annotation may specify a relationColumn explicitly;
			// if not, fall back to the entity's primary key.
			$relationColumn = $dependency->getRelationColumn() ?? $this->entityStore->getPrimaryKey($entity);
			
			if ($relationColumn === null) {
				return;
			}
			
			// Read the actual primary key value from this entity instance
			$parentKeyValue = $this->propertyHandler->get($entity, $relationColumn);
			$collection = $this->propertyHandler->get($entity, $property);
			
			// Scan all hydrated entities in the result for candidates that
			// belong to this parent via the foreign key (mappedBy property)
			foreach ($entities as $candidate) {
				// Skip entities that are not of the expected related type
				if (!($candidate instanceof $targetEntity)) {
					continue;
				}
				
				// Verify that the candidate's mappedBy property is actually
				// annotated as a relation pointing back to this parent entity type.
				// This prevents false matches in schemas where two unrelated entity
				// types share the same FK property name and overlapping ID values.
				if (!$this->candidateMapsToParent($candidate, $mappedBy, $objectClass)) {
					continue;
				}
				
				// Compare the candidate's FK value against the parent's primary key.
				// Only candidates where these match belong in this collection.
				if ($this->propertyHandler->get($candidate, $mappedBy) !== $parentKeyValue) {
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
		 * Returns true if the candidate's mappedBy property is annotated as a relation
		 * explicitly pointing back to $parentClass. Prevents false matches when two
		 * unrelated entity types share the same FK property name and overlapping ID values.
		 * @param object $candidate
		 * @param string $mappedBy
		 * @param string $parentClass
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function candidateMapsToParent(object $candidate, string $mappedBy, string $parentClass): bool {
			$candidateClass = $this->entityStore->resolveProxyClass($candidate);
			$deps = $this->entityStore->getAllDependencies($candidateClass)[$mappedBy] ?? [];
			
			foreach ($deps as $dep) {
				if (
					($dep instanceof ManyToOne || $dep instanceof OneToOne) &&
					$this->entityStore->resolveProxyClass($dep->getTargetEntity()) === $parentClass
				) {
					return true;
				}
			}
			
			return false;
		}
	}