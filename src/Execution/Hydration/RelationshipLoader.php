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
	use Quellabs\ObjectQuel\Metadata\RelationColumnMapping;
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

			$entityClass = $this->entityStore->normalizeEntityClass($entity);
			$mapping = $this->resolveOwningRelationMapping($entityClass, $property);

			// Reads every local FK component at once: all null means no relation to resolve
			// (returns null here), a partly null composite FK fails clearly in readForeignKey().
			$foreignKey = $this->readForeignKey($entity, $entityClass, $property, $mapping);

			if ($foreignKey === null) {
				return;
			}

			// Add the namespace to the target entity name and find the related entity.
			$targetEntity = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());

			// Check the UnitOfWork if it has the entity, keyed by every component at once
			$relationEntity = $this->unitOfWork->findEntity($targetEntity, $foreignKey);

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
			$entityClass = $this->entityStore->normalizeEntityClass($entity);
			$mapping = $this->resolveOwningRelationMapping($entityClass, $property);

			// Reads every local FK component at once. All null clears the relationship;
			// a partly null composite FK fails clearly rather than building a partial proxy.
			$foreignKey = $this->readForeignKey($entity, $entityClass, $property, $mapping);

			if ($foreignKey === null) {
				$this->propertyHandler->set($entity, $property, null);
				return;
			}

			// normalizeEntityClass ensures we have the fully-qualified class name.
			$targetEntityName = $this->entityStore->normalizeEntityClass($dependency->getTargetEntity());
			$targetMetadata = $this->entityStore->getMetadata($targetEntityName);

			// The fast, PK-based proxy only works when the foreign key's target properties
			// are exactly the target's full primary key: it seeds the proxy's PK and later
			// calls find() by that same key, relying on EntityHydrator to populate this exact
			// instance in place once it's re-discovered in the UnitOfWork by that PK. When the
			// relation references a different (non-PK) property instead, that key is not a
			// valid PK lookup at all, so a separate path resolves it correctly instead.
			$targetProperties = $mapping->targetProperties();
			sort($targetProperties);
			$targetIdentifierKeys = $targetMetadata->identifierKeys;
			sort($targetIdentifierKeys);

			if ($targetProperties === $targetIdentifierKeys) {
				// Create the proxy, seeded with every identifier component at once
				$proxy = $this->findOrCreateProxy($targetEntityName, $foreignKey);
			} else {
				$proxy = $this->createNonPrimaryKeyProxy($entity, $property, $targetEntityName, $foreignKey);
			}

			// Set the proxy on the original entity
			$this->propertyHandler->set($entity, $property, $proxy);
		}

		/**
		 * Resolves the owning relation's column-pair mapping for $property on $entityClass,
		 * failing clearly when none exists — a configuration error that should have been
		 * caught at metadata build time, so reaching here means a caller passed a property
		 * that isn't actually an owning ManyToOne/OneToOne.
		 * @param string $entityClass
		 * @param string $property
		 * @return RelationColumnMapping
		 * @throws EntityResolutionException
		 */
		private function resolveOwningRelationMapping(string $entityClass, string $property): RelationColumnMapping {
			$mapping = $this->entityStore->getMetadata($entityClass)->getRelationColumnMapping($property);

			if ($mapping === null) {
				throw new \LogicException("No resolved column mapping for relation '{$property}' on '{$entityClass}'.");
			}

			return $mapping;
		}

		/**
		 * Reads every local FK component of an owning relation from $entity at once.
		 * Returns null when every component is null (no relation to resolve). A partly
		 * null composite FK, or a component whose value isn't int|string, fails clearly
		 * rather than being silently treated as a (wrong) single-column lookup.
		 * @param object $entity
		 * @param string $entityClass
		 * @param string $property
		 * @param RelationColumnMapping $mapping
		 * @return array<string, int|string>|null Target property name => value, or null
		 * @throws HydrationException
		 */
		private function readForeignKey(object $entity, string $entityClass, string $property, RelationColumnMapping $mapping): ?array {
			$foreignKey = [];
			$anyNonNull = false;
			$anyNull = false;

			foreach ($mapping->pairs as $pair) {
				$value = $this->propertyHandler->get($entity, $pair->localProperty);

				if ($value === null) {
					$anyNull = true;
					continue;
				}

				if (!is_int($value) && !is_string($value)) {
					throw new HydrationException(
						"Cannot load proxy for relation '{$property}' on '{$entityClass}': local column " .
						"'{$pair->localProperty}' has a value that is not int or string."
					);
				}

				$anyNonNull = true;
				$foreignKey[$pair->targetProperty] = $value;
			}

			if (!$anyNonNull) {
				return null;
			}

			if ($anyNull) {
				throw new HydrationException(
					"Relation '{$property}' on '{$entityClass}' has a partially-null composite foreign key " .
					"(" . implode(', ', $mapping->localProperties()) . "); either every local column must be " .
					"set, or all of them must be null."
				);
			}

			return $foreignKey;
		}

		/**
		 * Returns an existing entity from the UnitOfWork, or instantiates and persists a new proxy.
		 * @param class-string $targetEntityName
		 * @param array<string, int|string> $foreignKey Target property name => value, every identifier component
		 * @return object
		 * @throws EntityResolutionException
		 */
		private function findOrCreateProxy(string $targetEntityName, array $foreignKey): object {
			// Check if the entity already exists in the UnitOfWork
			$existing = $this->unitOfWork->findEntity($targetEntityName, $foreignKey);

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
			$initializer = function () use ($entityManager, $targetEntityName, $foreignKey): void {
				$entityManager->find($targetEntityName, $foreignKey);
			};

			// Instantiate the proxy with the explicit initializer
			$proxy = new $proxyClassName($this->entityManager, $initializer);

			// Seed every identifier component on the proxy — ProxyCodeGenerator ensures every
			// identifier getter can be read without triggering initialization.
			foreach ($foreignKey as $targetProperty => $value) {
				$this->propertyHandler->set($proxy, $targetProperty, $value);
			}

			// Put the proxy under ownership
			$this->entityManager->persist($proxy);

			// Return the proxy
			return $proxy;
		}

		/**
		 * Builds a lazy proxy for an owning relation whose resolved target properties are not
		 * exactly the target's full primary key (e.g. a singular relation whose referencedColumn
		 * names a non-PK property). Such a proxy cannot be pre-registered under the identity-map
		 * key find() would use (the UnitOfWork only indexes entities by their real primary key),
		 * so it is resolved by findOneBy() on the full foreign key instead, and the result is
		 * copied into this exact proxy instance — mirroring the InverseOf scalar lazy-load
		 * pattern in loadInverseScalarLazy(), which faces the same constraint.
		 * @param object $entity The entity on which the proxy will be set
		 * @param string $property The property on $entity that will hold the proxy
		 * @param class-string $targetEntityName
		 * @param array<string, int|string> $foreignKey Target property name => value, every component
		 * @return object
		 */
		private function createNonPrimaryKeyProxy(object $entity, string $property, string $targetEntityName, array $foreignKey): object {
			$generator = $this->entityStore->getProxyGenerator();
			$proxyClassName = $generator->getProxyClass($targetEntityName);

			if (!class_exists($proxyClassName, false)) {
				require_once $generator->getProxyFilePath($targetEntityName);
			}

			// Capture references for the initializer closure
			$entityManager = $this->entityManager;
			$propertyHandler = $this->propertyHandler;
			$unitOfWork = $this->unitOfWork;

			return new $proxyClassName(
				$this->entityManager,
				function (ProxyInterface $proxy) use (
					$entityManager,
					$unitOfWork,
					$propertyHandler,
					$entity,
					$property,
					$targetEntityName,
					$foreignKey
				): void {
					// doInitialize() invokes this on every accessor call, so bail out once the
					// proxy has already been resolved to avoid re-running the findOneBy query.
					if ($proxy->isInitialized()) {
						return;
					}

					// findOneBy returns a fully hydrated entity registered in the UnitOfWork.
					$result = $entityManager->findOneBy($targetEntityName, $foreignKey);

					// Copy scalar fields to the proxy so generated accessors return resolved data.
					// Mark initialized first to prevent deserialize() from triggering recursive
					// initialization through the proxy's generated setters.
					if ($result !== null) {
						$proxy->setInitialized();
						$serializer = $unitOfWork->getSerializer();
						$serializer->deserialize($proxy, $serializer->serialize($result));
					}

					// Point the parent at the fully hydrated managed entity so subsequent reads
					// (including its own relations) resolve through it rather than the proxy.
					$propertyHandler->set($entity, $property, $result);
				}
			);
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
			$mapping = $this->resolveInverseRelationMapping($targetEntity, $relation, $entityClass);

			// One WHERE criterion per FK component, keyed by the dependent entity's own local
			// (FK) property names — never just the first component of a composite relation.
			$criteria = [];

			foreach ($mapping->pairs as $pair) {
				$criteria[$pair->localProperty] = $this->propertyHandler->get($entity, $pair->targetProperty);
			}

			// Load the proxy class file if needed
			$generator = $this->entityStore->getProxyGenerator();
			$proxyClassName = $generator->getProxyClass($targetEntity);

			if (!class_exists($proxyClassName, false)) {
				require_once $generator->getProxyFilePath($targetEntity);
			}

			// Capture references for the initializer closure
			$entityManager = $this->entityManager;
			$propertyHandler = $this->propertyHandler;
			$unitOfWork = $this->unitOfWork;

			$proxy = new $proxyClassName(
				$this->entityManager,
				function (ProxyInterface $proxy) use ($entityManager, $unitOfWork, $propertyHandler, $entity, $property, $targetEntity, $criteria): void {
					// doInitialize() invokes this on every accessor call, so bail out once the
					// proxy has already been resolved to avoid re-running the findOneBy query.
					if ($proxy->isInitialized()) {
						return;
					}

					// findOneBy returns a fully hydrated entity registered in the UnitOfWork.
					$result = $entityManager->findOneBy($targetEntity, $criteria);

					// Copy the resolved entity's scalar columns into the proxy itself. Generated
					// accessors read the proxy's own fields via parent::, so the access that triggered
					// initialization must see real data rather than an empty proxy. Marked initialized
					// first to prevent deserialize() from triggering recursive initialization through
					// the proxy's generated setters.
					if ($result !== null) {
						$proxy->setInitialized();
						$serializer = $unitOfWork->getSerializer();
						$serializer->deserialize($proxy, $serializer->serialize($result));
					}

					// Point the parent at the fully hydrated managed entity so subsequent reads
					// (including its own relations) resolve through it rather than the proxy.
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
		private function loadInverseCollectionLazy(object $entity, string $entityClass, string $property, InverseOf $dependency): void {
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
			$mapping = $this->resolveInverseRelationMapping($targetEntity, $relation, $entityClass);

			// One criterion per FK component, keyed by the dependent entity's own local (FK)
			// property names — EntityCollection queries by column property (e.g. userId), not
			// by the relation property (e.g. user), since relation properties are rejected by
			// the semantic analyser. Never just the first component of a composite relation.
			$criteria = [];

			foreach ($mapping->pairs as $pair) {
				$criteria[$pair->localProperty] = $this->propertyHandler->get($entity, $pair->targetProperty);
			}

			// Create an Entity Collection
			$proxy = EntityCollection::forCriteria($this->entityManager, $targetEntity, $criteria);

			// Assign it to entity
			$this->propertyHandler->set($entity, $property, $proxy);
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
		 * parent key — so behavior and result-set ordering are preserved.
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
			$mapping = $this->resolveInverseRelationMapping($targetEntity, $relation, $entityClass);

			// The parent's own value for every pair's target property, in pair order —
			// the tuple a matching candidate's full local (FK) tuple must equal exactly.
			$parentKeyTuple = [];

			foreach ($mapping->pairs as $pair) {
				$parentKeyTuple[] = $this->propertyHandler->get($entity, $pair->targetProperty);
			}

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

					// Bucket by the candidate's full local (FK) tuple, in the same pair order as
					// $parentKeyTuple — every component must match, not just the first.
					$candidateTuple = [];

					foreach ($mapping->pairs as $pair) {
						$candidateTuple[] = $this->propertyHandler->get($candidate, $pair->localProperty);
					}

					$buckets[$this->inverseBucketKey($candidateTuple)][] = $candidate;
				}

				$index[$indexKey] = $buckets;
			}

			return $index[$indexKey][$this->inverseBucketKey($parentKeyTuple)] ?? [];
		}

		/**
		 * Encodes an ordered tuple of scalar FK values into a bucket key that preserves strict
		 * (===) per-component semantics: each value is first type-tagged (keeping int 1, string
		 * "1", null and false distinct, as the previous single-column version did), then every
		 * tagged component is wrapped in an explicit length prefix before being concatenated —
		 * a component itself containing the ':' or any other separator character can never be
		 * misread as a boundary between components, however many there are.
		 * @param array<int, mixed> $tuple
		 * @return string
		 */
		private function inverseBucketKey(array $tuple): string {
			$segments = [];

			foreach ($tuple as $value) {
				$tagged = match (true) {
					is_int($value) => 'i:' . $value,
					is_string($value) => 's:' . $value,
					$value === null => 'n:',
					is_bool($value) => 'b:' . ($value ? '1' : '0'),
					default => 'x:' . var_export($value, true),
				};

				$segments[] = strlen($tagged) . ':' . $tagged;
			}

			return implode('', $segments);
		}

		/**
		 * Returns the ManyToOne or owning-side OneToOne annotation for a given property
		 * on an entity, or null if no such relation exists.
		 * Centralizes the repeated pattern of checking both relation types.
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
		 * Resolves the full column-pair mapping for an InverseOf's via relation: looks up the
		 * ManyToOne or OneToOne annotation on the dependent entity's via property, validates
		 * that it points back to the owner, and returns its resolved mapping — every pair's
		 * localProperty lives on the dependent (candidate) entity, every targetProperty on
		 * the owner. Replaces resolving a single referenced property, which only ever captured
		 * the first component of a composite relation.
		 * @param string $targetEntity Fully qualified dependent entity class name
		 * @param string $relation Property name on the dependent entity that holds the FK
		 * @param string $ownerClass Fully qualified owner entity class name
		 * @return RelationColumnMapping
		 * @throws EntityResolutionException
		 * @throws \RuntimeException When via does not reference a valid back-pointing relation
		 */
		private function resolveInverseRelationMapping(string $targetEntity, string $relation, string $ownerClass): RelationColumnMapping {
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

			$mapping = $dependentMetadata->getRelationColumnMapping($relation);

			if ($mapping === null) {
				throw new \RuntimeException(
					"InverseOf relation='{$relation}' on {$ownerClass} has no resolved column mapping on {$targetEntity}."
				);
			}

			return $mapping;
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
			
			// An inverse property is a collection only when typed as a CollectionInterface
			// implementation. A plain 'array' is rejected at metadata build time, so it is not
			// treated as a collection here (doing so would misroute it to the scalar loaders).
			return is_a($typeName, CollectionInterface::class, true);
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