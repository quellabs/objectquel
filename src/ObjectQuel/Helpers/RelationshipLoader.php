<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\EntityCollection;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\EntityManager;
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
		 * Determines the correct property name on the proxy based on the dependency.
		 * @param $dependency mixed The OneToOne dependency.
		 * @return string The name of the property.
		 */
		private function determineRelationPropertyName(mixed $dependency): string {
			return !empty($dependency->getInversedBy()) ? $dependency->getInversedBy() : $dependency->getMappedBy();
		}
		
		/**
		 * Processes the dependency of an entity and updates the property with the specified dependency.
		 * This function checks if the current relation is null or not initialized and then searches
		 * for the related entity based on the given dependency. If a matching entity
		 * is found, the property of the current entity is updated to reflect this relationship.
		 * @param object $entity The entity whose dependency is being processed.
		 * @param string $property The property of the entity that needs to be updated.
		 * @param mixed $dependency The dependency used to find the related entity.
		 */
		private function processEntityDependency(object $entity, string $property, mixed $dependency): void {
			// Get the current value of the property.
			$currentRelation = $this->propertyHandler->get($entity, $property);
			
			// Check if the current relation is already set and if it's not an uninitialized proxy.
			if ($currentRelation !== null &&
				(!($currentRelation instanceof ProxyInterface) || $currentRelation->isInitialized())) {
				return;
			}
			
			// Determine the column and value for the relation based on the dependency.
			$relationColumn = $dependency->getRelationColumn();
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			// If the value of the relation column is 0 or null, the operation does not continue.
			if (empty($relationColumnValue)) {
				return;
			}
			
			// Determine the name and property of the target entity based on the dependency.
			$targetEntityName = $dependency->getTargetEntity();
			$inversedPropertyName = $this->getInversedPropertyName($dependency);
			
			// Add the namespace to the target entity name and find the related entity.
			$targetEntity = $this->entityStore->normalizeEntityName($targetEntityName);
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
		 * Determines the appropriate property name for the inverse relationship based on the dependency type.
		 * This method extracts the correct property name that should be used when navigating
		 * from the target entity back to the source entity.
		 * @param object $dependency The relationship dependency (OneToOne or ManyToOne).
		 * @return string The name of the inverse relationship property.
		 */
		private function getInversedPropertyName(object $dependency): string {
			// Check the dependency type and determine the appropriate property
			if ($dependency instanceof OneToOne) {
				return $dependency->getInversedBy() ?: $dependency->getMappedBy();
			} elseif ($dependency instanceof ManyToOne) {
				return $dependency->getInversedBy(); // ManyToOne typically only has getInversedBy
			} else {
				return '';
			}
		}
		
		/**
		 * Creates a proxy object for a given dependency and sets it on the entity.
		 * This method handles lazy loading of relationships by creating proxy instances
		 * that will load their data only when accessed.
		 * @param object $entity The entity on which to set the proxy
		 * @param string $property The name of the property where the proxy will be set
		 * @param object $dependency The dependency object that describes the relationship
		 */
		private function createAndSetProxy(object $entity, string $property, object $dependency): void {
			// Determine the relation column (the column containing the foreign key)
			$relationColumn = $this->getRelationColumn($entity, $dependency);
			
			// Get the primary key value. If it's empty, clear the relationship
			$relationColumnValue = $this->propertyHandler->get($entity, $relationColumn);
			
			if (empty($relationColumnValue)) {
				$this->propertyHandler->set($entity, $property, null);
				return;
			}
			
			// Gather information needed to create the proxy
			$targetEntityName = $dependency->getTargetEntity();
			$proxyClassName = $this->entityManager->getEntityStore()->getProxyGenerator()->getProxyClass($targetEntityName);
			
			if ($dependency instanceof ManyToOne) {
				$relationPropertyName = $dependency->getInversedBy();
			} else {
				$relationPropertyName = $this->determineRelationPropertyName($dependency);
			}
			
			// Check if the entity already exists in the UnitOfWork
			$proxyEntity = $this->unitOfWork->findEntity($targetEntityName, [
				$relationPropertyName => $relationColumnValue
			]);
			
			// Create a new proxy if no existing entity was found
			if ($proxyEntity === null) {
				$proxyEntity = new $proxyClassName($this->entityManager);
				$this->propertyHandler->set($proxyEntity, $relationPropertyName, $relationColumnValue);
				$this->entityManager->persist($proxyEntity);
			}
			
			// Set the proxy on the original entity
			$this->propertyHandler->set($entity, $property, $proxyEntity);
		}
		
		/**
		 * Filters and returns an array of valid OneToOne and ManyToOne dependencies for a given entity and property.
		 * @param object $entity The entity whose property is being checked.
		 * @param string $property The name of the entity's property.
		 * @param array $dependencies An array of dependencies to filter.
		 * @return array An array of valid OneToOne and ManyToOne dependencies.
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
		 * Returns true if the identifier is an entity, false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&
				$ast->getRange() instanceof AstRangeDatabase &&
				!$ast->hasNext()
			);
		}
		
		/**
		 * Filters and returns an array of valid OneToMany dependencies for a given entity and property.
		 * @param object $entity The entity whose property is being checked.
		 * @param string $property The name of the entity's property.
		 * @param array $dependencies An array of dependencies to filter.
		 * @return array An array of valid OneToMany dependencies.
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
		 * Determines the relation column for a given entity and dependency.
		 * This method finds the appropriate column that stores the foreign key value
		 * for the relationship, either using the explicitly defined relation column
		 * or falling back to the primary key of the entity.
		 * @param object $entity The entity for which the relation column is determined
		 * @param object $dependency The dependency object describing the relationship
		 * @return string The name of the relation column
		 */
		private function getRelationColumn(object $entity, object $dependency): string {
			$relationColumn = $dependency->getRelationColumn();
			
			if (empty($relationColumn)) {
				$primaryKeys = $this->entityStore->getIdentifierKeys($entity);
				$relationColumn = $primaryKeys[0];
			}
			
			return $relationColumn;
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
				// Omit non entity values
				if (!$this->identifierIsEntity($value->getExpression())) {
					continue;
				}
				
				// Check if the entity matches and if the join property occurs in the range
				$entity = $value->getExpression();
				$range = $entity->getRange();
				
				if (
					$entity->getEntityName() === $targetEntity &&
					$range->hasJoinProperty($targetEntity, $joinProperty)
				) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Sets both OneToOne and ManyToOne relationships for each entity in the given row.
		 * @param array $filteredEntities An array of filtered entities for which the relationships should be set.
		 * @return void
		 */
		private function setDirectRelations(array $filteredEntities): void {
			foreach ($filteredEntities as $entity) {
				// Normalize the entity class name
				$entityClass = $this->entityStore->normalizeEntityName(get_class($entity));
				
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
		 * @param array $filteredRows The entities that need to be processed
		 * @return void
		 */
		private function setupProxyRelations(array $filteredRows): void {
			// Loop through all filtered entities
			foreach ($filteredRows as $value) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->normalizeEntityName(get_class($value));
				
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
		 * @param array $filteredRows The rows that need to be processed
		 * @return void
		 */
		private function setupOneToManyCollections(array $filteredRows): void {
			// Loop through all filtered rows
			foreach ($filteredRows as $value) {
				// Get the normalized name of the entity class
				$objectClass = $this->entityStore->normalizeEntityName(get_class($value));
				
				// Get all dependencies of the entity class
				$entityDependencies = $this->entityStore->getAllDependencies($objectClass);
				
				// Loop through all properties and their dependencies
				foreach ($entityDependencies as $property => $dependencies) {
					// Filter empty One-to-Many dependencies for the current value and property
					$validDependencies = $this->filterEmptyOneToManyDependencies($value, $property, $dependencies);
					
					// Create and set a collection of entities for each valid dependency
					foreach ($validDependencies as $dependency) {
						$targetEntity = $this->entityStore->normalizeEntityName($dependency->getTargetEntity());
						$relationColumn = $this->getRelationColumn($value, $dependency);
						$mappedBy = $dependency->getMappedBy();
						
						// Do nothing if the data for this query was requested. There is simply no data,
						// so there's no point in lazy loading this data. We keep the empty collection.
						if ($this->wasEntityRequested($objectClass, $targetEntity, $mappedBy)) {
							continue;
						}
						
						// Create an Entity Collection
						$primaryKeyValue = $this->propertyHandler->get($value, $relationColumn);
						
						$proxy = new EntityCollection(
							$this->entityManager, $targetEntity, $dependency->getMappedBy(),
							$primaryKeyValue, $dependency->getOrderBy()
						);
						
						$this->propertyHandler->set($value, $property, $proxy);
					}
				}
			}
		}
		
		/**
		 * Loads all relationships for a set of entities
		 * @param array $entities The entities to load relationships for
		 */
		public function loadRelationships(array $entities): void {
			// Set direct entity-to-entity relationships
			$this->setDirectRelations($entities);
			
			// Set up proxies for empty relationships
			$this->setupProxyRelations($entities);
			
			// Set up collections for empty OneToMany relations
			$this->setupOneToManyCollections($entities);
		}
	}