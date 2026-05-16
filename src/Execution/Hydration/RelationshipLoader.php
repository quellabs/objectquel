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
			// Set direct entity-to-entity relationships
			$this->setDirectRelations($entities);
			
			// Set up proxies for empty relationships
			$this->setupProxyRelations($entities);
			
			// Set up collections for empty OneToMany relations
			$this->setupOneToManyCollections($entities);
			
			// Wire hydrated related entities back into parent collections
			// for OneToMany relations that were explicitly joined in the query
			$this->wireJoinedCollections($entities);
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
			
			// Gather information needed to create the proxy
			$targetEntityName = $dependency->getTargetEntity();
			
			// resolveTargetProperty handles both ManyToOne and OneToOne transparently
			$relationPropertyName = $this->entityStore->resolveTargetProperty($dependency);
			
			// Check if the entity already exists in the UnitOfWork
			$proxyEntity = $this->unitOfWork->findEntity($targetEntityName, [
				$relationPropertyName => $relationColumnValue
			]);
			
			// Create a new proxy if no existing entity was found
			if ($proxyEntity === null) {
				$proxyGenerator = $this->entityStore->getProxyGenerator();
				$proxyClassName = $proxyGenerator->getProxyClass($targetEntityName);
				$proxyFilePath = $proxyGenerator->getProxyFilePath($targetEntityName);
				
				// Load the proxy class file if it doesn't exist
				if (!class_exists($proxyClassName, false)) {
					require_once $proxyFilePath;
				}
				
				// Instantiate the proxy
				$proxyEntity = new $proxyClassName($this->entityManager);
				
				// Set the primary key on the proxy using the target entity's primary key property name
				$targetPrimaryKeys = $this->entityStore->getIdentifierKeys($targetEntityName);
				$this->propertyHandler->set($proxyEntity, $targetPrimaryKeys[0], $relationColumnValue);
				
				// Put the proxy under ownership
				$this->entityManager->persist($proxyEntity);
			}
			
			// Set the proxy on the original entity
			$this->propertyHandler->set($entity, $property, $proxyEntity);
		}
		
		/**
		 * Filters and returns an array of valid OneToOne and ManyToOne dependencies for a given entity and property.
		 * @param object $entity The entity whose property is being checked.
		 * @param string $property The name of the entity's property.
		 * @param array<int, ManyToOne|OneToOne|OneToMany> $dependencies An array of dependencies to filter.
		 * @return array<int, ManyToOne|OneToOne> An array of valid OneToOne and ManyToOne dependencies.
		 */
		private function filterValidDependencies(object $entity, string $property, array $dependencies): array {
			$validDependencies = [];
			
			foreach ($dependencies as $dependency) {
				// Check if the dependency is an instance of OneToOne or ManyToOne
				if (!($dependency instanceof OneToOne) && !($dependency instanceof ManyToOne)) {
					// Continue to the next iteration if the dependency is not a OneToMany
					continue;
				}
				
				// Get the value of the property from the entity
				$propertyValue = $this->propertyHandler->get($entity, $property);
				
				// Add the value to the list of valid dependencies
				if ($propertyValue === null) {
					$validDependencies[] = $dependency;
				}
			}
			
			return $validDependencies;
		}
		
		/**
		 * Filters and returns an array of valid OneToMany dependencies for a given entity and property.
		 * @param object $entity The entity whose property is being checked.
		 * @param string $property The name of the entity's property.
		 * @param array<int, ManyToOne|OneToOne|OneToMany> $dependencies An array of dependencies to filter.
		 * @return array<int, OneToMany> An array of valid OneToMany dependencies.
		 */
		private function filterEmptyOneToManyDependencies(object $entity, string $property, array $dependencies): array {
			$validDependencies = [];
			
			foreach ($dependencies as $dependency) {
				// Check if the dependency is an instance of OneToMany
				if (!($dependency instanceof OneToMany)) {
					// Continue to the next iteration if the dependency is not a OneToMany
					continue;
				}
				
				// Get the value of the property from the entity
				$propertyValue = $this->propertyHandler->get($entity, $property);
				
				// Add the value to the list of valid dependencies
				if ($propertyValue instanceof Collection && $propertyValue->isEmpty()) {
					$validDependencies[] = $dependency;
				}
			}
			
			// Return the array of valid dependencies
			return $validDependencies;
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
		 * Sets both OneToOne and ManyToOne relationships for each entity in the given row.
		 * @param array<int, object> $filteredEntities An array of filtered entities.
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function setDirectRelations(array $filteredEntities): void {
			foreach ($filteredEntities as $entity) {
				// Normalize the entity class name
				$entityClass = $this->entityStore->resolveProxyClass($entity);
				
				// Dependencies
				$entityDependencies = $this->entityStore->getAllDependencies($entityClass);
				
				// Check if there are relationships for the entity class
				if (empty($entityDependencies)) {
					continue;
				}
				
				// Iterate through each property and its dependencies in the relationship cache
				foreach ($entityDependencies as $property => $dependencies) {
					// Iterate through each dependency of the property
					foreach ($dependencies as $dependency) {
						// Check if the dependency is a OneToOne or ManyToOne relationship
						if (!($dependency instanceof OneToOne) && !($dependency instanceof ManyToOne)) {
							continue;
						}
						
						// Process the entity dependency
						$this->processEntityDependency($entity, $property, $dependency);
					}
				}
			}
		}
		
		/**
		 * Promotes empty relationships to proxy objects for the given filtered entities.
		 * This method identifies entity properties that have OneToOne or ManyToOne relationships
		 * which are currently null, and creates appropriate proxy objects for lazy loading
		 * those relationships when they are accessed.
		 * @param array<int, object> $filteredRows The entities that need to be processed
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function setupProxyRelations(array $filteredRows): void {
			// Loop through all filtered entities
			foreach ($filteredRows as $value) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->resolveProxyClass($value);
				
				// Get all dependencies of the entity class
				$entityDependencies = $this->entityStore->getAllDependencies($objectClass);
				
				// Loop through all properties and their dependencies
				foreach ($entityDependencies as $property => $dependencies) {
					// Filter for valid dependencies for the current entity and property.
					// Valid dependencies are those where the property is currently null.
					// Create and set a proxy for each valid dependency.
					foreach ($this->filterValidDependencies($value, $property, $dependencies) as $dependency) {
						$this->createAndSetProxy($value, $property, $dependency);
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
		private function setupOneToManyCollections(array $filteredRows): void {
			// Loop through all filtered rows
			foreach ($filteredRows as $entity) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->resolveProxyClass($entity);
				
				// Get all dependencies of the entity class
				$entityDependencies = $this->entityStore->getAllDependencies($objectClass);
				
				// Loop through all properties and their dependencies
				foreach ($entityDependencies as $property => $dependencies) {
					// Filter empty One-to-Many dependencies for the current value and property
					$validDependencies = $this->filterEmptyOneToManyDependencies($entity, $property, $dependencies);
					
					// Create and set a collection of entities for each valid dependency
					foreach ($validDependencies as $dependency) {
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
		 * Wires already-hydrated related entities back into their parent entity's collection,
		 * for OneToMany relations where the related entity was explicitly joined in the query.
		 *
		 * setupOneToManyCollections() deliberately skips these relations (leaving the collection
		 * empty) because it assumes the join already produced the data. This method completes
		 * that contract by scanning the hydrated entity set and populating the collection from it,
		 * rather than issuing an additional query.
		 *
		 * @param array<int, object> $entities All hydrated entities from the result set
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function wireJoinedCollections(array $entities): void {
			foreach ($entities as $entity) {
				// Resolve the real class name in case this is a proxy object
				$objectClass = $this->entityStore->resolveProxyClass($entity);
				
				// Get all annotated relation dependencies for this entity class
				$entityDependencies = $this->entityStore->getAllDependencies($objectClass);
				
				foreach ($entityDependencies as $property => $dependencies) {
					foreach ($dependencies as $dependency) {
						// We only care about OneToMany here; OneToOne and ManyToOne
						// are handled by setDirectRelations and setupProxyRelations
						if (!($dependency instanceof OneToMany)) {
							continue;
						}
						
						// Fetch mappedBy
						$mappedBy = $dependency->getMappedBy();
						
						// If not given, skip the dependency
						if ($mappedBy === null || $mappedBy === '') {
							continue;
						}
						
						// Resolve the full class name of the related entity
						$targetEntity = $this->entityStore->resolveProxyClass($dependency->getTargetEntity());
						
						// Only handle relations where the related entity was explicitly
						// joined in the query. If it wasn't requested, setupOneToManyCollections
						// already set up an EntityCollection for lazy loading instead.
						if (!$this->wasEntityRequested($objectClass, $targetEntity, $mappedBy)) {
							continue;
						}
						
						// Determine which property on this entity holds its primary key value.
						// The OneToMany annotation may specify a relationColumn explicitly;
						// if not, fall back to the entity's primary key.
						$relationColumn = $dependency->getRelationColumn() ?? $this->entityStore->getPrimaryKey($entity);
						
						if ($relationColumn === null) {
							continue;
						}
						
						// Read the actual primary key value from this entity instance
						$parentKeyValue = $this->propertyHandler->get($entity, $relationColumn);
						
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
							$candidateClass = $this->entityStore->resolveProxyClass($candidate);
							$candidateDependencies = $this->entityStore->getAllDependencies($candidateClass);
							$mappedByDependencies = $candidateDependencies[$mappedBy] ?? [];
							
							$pointsToParent = false;
							
							foreach ($mappedByDependencies as $mappedByDep) {
								// The mappedBy property must be a ManyToOne or OneToOne
								// annotation explicitly referencing the parent entity class
								if (($mappedByDep instanceof ManyToOne || $mappedByDep instanceof OneToOne) &&
									$this->entityStore->resolveProxyClass($mappedByDep->getTargetEntity()) === $objectClass
								) {
									$pointsToParent = true;
									break;
								}
							}
							
							// Skip candidates whose mappedBy property does not explicitly
							// reference this parent entity type — they belong to a different relation
							if (!$pointsToParent) {
								continue;
							}
							
							// Compare the candidate's FK value against the parent's primary key.
							// Only candidates where these match belong in this collection.
							$fkValue = $this->propertyHandler->get($candidate, $mappedBy);
							
							if ($fkValue !== $parentKeyValue) {
								continue;
							}
							
							// Add the candidate to the parent's collection, but only if
							// it isn't already present — avoids duplicates when the same
							// result set is processed more than once
							$collection = $this->propertyHandler->get($entity, $property);
							
							if ($collection instanceof CollectionInterface && !$collection->contains($candidate)) {
								$collection->add($candidate);
							}
						}
					}
				}
			}
		}
	}