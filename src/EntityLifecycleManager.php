<?php
	
	/**
	 * ObjectQuel - A Sophisticated Object-Relational Mapping (ORM) System
	 *
	 * ObjectQuel is an ORM that brings a fresh approach to database interaction,
	 * featuring a unique query language, a streamlined architecture, and powerful
	 * entity relationship management. It implements the Data Mapper pattern for
	 * clear separation between domain models and underlying database structures.
	 *
	 * @author      Floris van den Berg
	 * @copyright   Copyright (c) 2025 ObjectQuel
	 * @license     MIT
	 * @version     1.0.0
	 * @package     Quellabs\ObjectQuel
	 */

	namespace Quellabs\ObjectQuel;
	
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\LifecycleAware;
	use Quellabs\ObjectQuel\Annotations\Orm\PrePersist;
	use Quellabs\ObjectQuel\Annotations\Orm\PostPersist;
	use Quellabs\ObjectQuel\Annotations\Orm\PreUpdate;
	use Quellabs\ObjectQuel\Annotations\Orm\PostUpdate;
	use Quellabs\ObjectQuel\Annotations\Orm\PreDelete;
	use Quellabs\ObjectQuel\Annotations\Orm\PostDelete;
	use Quellabs\SignalHub\SignalHub;
	
	/**
	 * Manages lifecycle event callbacks for entities
	 */
	class EntityLifecycleManager {
		
		/**
		 * @var SignalHub The SignalHub instance for connecting to signals
		 */
		private SignalHub $signalHub;
		
		/**
		 * @var EntityStore The EntityStore for accessing annotations
		 */
		private EntityStore $entityStore;
		
		/**
		 * @var array Cache of entity classes that have lifecycle callbacks
		 */
		private array $lifecycleAwareCache = [];
		
		/**
		 * @var array Cache of entity lifecycle methods by class and event type
		 */
		private array $lifecycleMethodsCache = [];
		
		/**
		 * Constructor
		 * @param SignalHub $signalHub
		 * @param EntityStore $entityStore
		 */
		public function __construct(SignalHub $signalHub, EntityStore $entityStore) {
			$this->signalHub = $signalHub;
			$this->entityStore = $entityStore;
			
			// Connect to all entity lifecycle signals
			$this->connectToSignals();
		}
		
		/**
		 * Connect to all entity lifecycle signals
		 * @return void
		 * @throws \Exception
		 */
		private function connectToSignals(): void {
			$this->signalHub->getSignal('orm.prePersist')->connect([$this, 'handlePrePersist']);
			$this->signalHub->getSignal('orm.postPersist')->connect([$this, 'handlePostPersist']);
			$this->signalHub->getSignal('orm.preUpdate')->connect([$this, 'handlePreUpdate']);
			$this->signalHub->getSignal('orm.postUpdate')->connect([$this, 'handlePostUpdate']);
			$this->signalHub->getSignal('orm.preDelete')->connect([$this, 'handlePreDelete']);
			$this->signalHub->getSignal('orm.postDelete')->connect([$this, 'handlePostDelete']);
		}
		
		/**
		 * Handle prePersist event
		 * @param object $entity The entity being persisted
		 */
		public function handlePrePersist(object $entity): void {
			$this->executeLifecycleMethods($entity, PrePersist::class);
		}
		
		/**
		 * Handle postPersist event
		 * @param object $entity The entity that was persisted
		 */
		public function handlePostPersist(object $entity): void {
			$this->executeLifecycleMethods($entity, PostPersist::class);
		}
		
		/**
		 * Handle preUpdate event
		 * @param object $entity The entity being updated
		 */
		public function handlePreUpdate(object $entity): void {
			$this->executeLifecycleMethods($entity, PreUpdate::class);
		}
		
		/**
		 * Handle postUpdate event
		 * @param object $entity The entity that was updated
		 */
		public function handlePostUpdate(object $entity): void {
			$this->executeLifecycleMethods($entity, PostUpdate::class);
		}
		
		/**
		 * Handle preDelete event
		 * @param object $entity The entity being deleted
		 */
		public function handlePreDelete(object $entity): void {
			$this->executeLifecycleMethods($entity, PreDelete::class);
		}
		
		/**
		 * Handle postDelete event
		 * @param object $entity The entity that was deleted
		 */
		public function handlePostDelete(object $entity): void {
			$this->executeLifecycleMethods($entity, PostDelete::class);
		}
		
		/**
		 * Execute all lifecycle methods of a specific annotation type on an entity
		 * @param object $entity The entity to execute methods on
		 * @param string $annotationClass The annotation class to look for
		 */
		private function executeLifecycleMethods(object $entity, string $annotationClass): void {
			// Skip if entity class doesn't have lifecycle callbacks
			if (!$this->isLifecycleAware($entity)) {
				return;
			}
			
			// Get methods with this annotation
			$methods = $this->getLifecycleMethods(get_class($entity), $annotationClass);
			
			// Execute each method
			foreach ($methods as $method) {
				$entity->$method();
			}
		}
		
		/**
		 * Check if an entity has the @Orm\LifecycleAware class annotation
		 * This determines whether the entity has opted into the lifecycle callback system.
		 * @param object $entity  The entity instance to check
		 * @return bool  True if the entity has the @Orm\LifecycleAware annotation
		 */
		private function isLifecycleAware(object $entity): bool {
			try {
				$className = get_class($entity);
				
				// Check cache first
				if (isset($this->lifecycleAwareCache[$className])) {
					return $this->lifecycleAwareCache[$className];
				}
				
				// Check if the class is lifecycle aware
				$isLifeCycleAware = $this->entityStore->getAnnotationReader()->classHasAnnotation($entity, LifecycleAware::class);
				return $this->lifecycleAwareCache[$className] = $isLifeCycleAware;
			} catch (ParserException $e) {
				return false;
			}
		}
		
		/**
		 * Get all methods with a specific lifecycle annotation for an entity class
		 * @param string $entityClass The entity class name
		 * @param string $annotationClass The annotation class to look for
		 * @return array List of method names
		 */
		private function getLifecycleMethods(string $entityClass, string $annotationClass): array {
			// Create a cache key
			$cacheKey = $entityClass . ':' . $annotationClass;
			
			// Check cache first
			if (isset($this->lifecycleMethodsCache[$cacheKey])) {
				return $this->lifecycleMethodsCache[$cacheKey];
			}
			
			// Find methods with this annotation
			$methods = [];
			$reflectionClass = new \ReflectionClass($entityClass);
			
			foreach ($reflectionClass->getMethods() as $method) {
				if ($this->entityStore->getAnnotationReader()->methodHasAnnotation($entityClass, $method->getName(), $annotationClass)) {
					$methods[] = $method->getName();
				}
			}
			
			// Cache the result
			$this->lifecycleMethodsCache[$cacheKey] = $methods;
			
			// Return methods
			return $methods;
		}
		
		/**
		 * Clear caches for an entity class
		 * @param string $entityClass The entity class name
		 */
		public function clearCache(string $entityClass): void {
			unset($this->lifecycleAwareCache[$entityClass]);
			
			foreach (array_keys($this->lifecycleMethodsCache) as $key) {
				if (str_starts_with($key, $entityClass . ':')) {
					unset($this->lifecycleMethodsCache[$key]);
				}
			}
		}
		
		/**
		 * Clear all caches
		 */
		public function clearAllCaches(): void {
			$this->lifecycleAwareCache = [];
			$this->lifecycleMethodsCache = [];
		}
	}