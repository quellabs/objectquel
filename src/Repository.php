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
	
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Base repository class that users can extend for better IDE type support
	 * This class follows the Repository pattern for data access abstraction
	 * It provides generic methods to fetch entities from the database
	 * @template TEntity of object
	 */
	abstract class Repository {
		
		/**
		 * Entity manager instance responsible for database operations
		 */
		protected EntityManager $entityManager;
		
		/**
		 * The fully qualified class name of the entity this repository manages
		 * @var class-string<TEntity>
		 */
		protected string $entityClass;
		
		/**
		 * Constructor initializes the repository with an entity manager and entity class
		 * @param EntityManager $entityManager The entity manager to use for database operations
		 * @param class-string<TEntity> $entityClass The fully qualified class name of the entity
		 */
		public function __construct(EntityManager $entityManager, string $entityClass) {
			$this->entityManager = $entityManager;
			$this->entityClass = $entityClass;
		}
		
		/**
		 * Find a single entity by its primary key/identifier
		 * @param mixed $id The primary key value to search for
		 * @return object|null The found entity or null if not found
		 * @throws QuelException If a database error occurs during the operation
		 */
		public function find(mixed $id): ?object {
			return $this->entityManager->find($this->entityClass, $id);
		}
		
		/**
		 * Find multiple entities matching the given criteria
		 * @param array<string, mixed> $criteria Associative array of field names and values to match
		 * @return array<TEntity> Array of matching entities
		 * @throws QuelException If a database error occurs during the operation
		 */
		public function findBy(array $criteria): array {
			return $this->entityManager->findBy($this->entityClass, $criteria);
		}
	}