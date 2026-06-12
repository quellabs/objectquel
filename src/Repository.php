<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║   ██████╗ ██████╗      ██╗███████╗ ██████╗████████╗ ██████╗ ██╗   ██╗███████╗██╗      ║
	 * ║  ██╔═══██╗██╔══██╗     ██║██╔════╝██╔════╝╚══██╔══╝██╔═══██╗██║   ██║██╔════╝██║      ║
	 * ║  ██║   ██║██████╔╝     ██║█████╗  ██║        ██║   ██║   ██║██║   ██║█████╗  ██║      ║
	 * ║  ██║   ██║██╔══██╗██   ██║██╔══╝  ██║        ██║   ██║▄▄ ██║██║   ██║██╔══╝  ██║      ║
	 * ║  ╚██████╔╝██████╔╝╚█████╔╝███████╗╚██████╗   ██║   ╚██████╔╝╚██████╔╝███████╗███████╗ ║
	 * ║   ╚═════╝ ╚═════╝  ╚════╝ ╚══════╝ ╚═════╝   ╚═╝    ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝ ║
	 * ║                                                                                       ║
	 * ║  ObjectQuel - Powerful Object-Relational Mapping built on the Data Mapper pattern     ║
	 * ║                                                                                       ║
	 * ║  Clean separation between entities and persistence logic with an intuitive,           ║
	 * ║  object-oriented query language. Powered by CakePHP's robust database foundation.     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\ObjectQuel;
	
	use Quellabs\ObjectQuel\Exception\EntityNotFoundException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
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
		 * Convenience method to retrieve the ObjectQuel EntityManager service.
		 * @return EntityManager The entity manager instance
		 */
		protected function em(): EntityManager {
			return $this->entityManager;
		}
		
		/**
		 * Find a single entity by its primary key/identifier
		 * @param int|string $primaryKey The primary key of the entity
		 * @return TEntity|null The found entity or null if not found
		 * @throws QuelException If a database error occurs during the operation
		 * @throws EntityResolutionException
		 */
		public function find(int|string $primaryKey): ?object {
			return $this->em()->find($this->entityClass, $primaryKey);
		}
		
		/**
		 * Find a single entity by its primary key/identifier
		 * @param int|string $primaryKey The primary key of the entity
		 * @return TEntity|null The found entity or null if not found
		 * @throws QuelException If a database error occurs during the operation
		 * @throws EntityResolutionException
		 * @throws EntityNotFoundException
		 */
		public function findOrFail(int|string $primaryKey): ?object {
			return $this->em()->findOrFail($this->entityClass, $primaryKey);
		}
		
		/**
		 * Find multiple entities matching the given criteria
		 * @param array<string, mixed> $searchData Associative array of field names and values to filter by
		 * @param array<string, string>|null $sortBy Associative array of field names and sort directions
		 * @return array<TEntity> Array of matching entities
		 * @throws QuelException|EntityResolutionException If a database error occurs during the operation
		 */
		public function findBy(array $searchData, ?array $sortBy=null): array {
			return $this->em()->findBy($this->entityClass, $searchData, $sortBy);
		}
		
		/**
		 * Find a single entity matching the given criteria
		 * @param array<string, mixed> $searchData Associative array of field names and values to filter by
		 * @param array<string, string>|null $sortBy Associative array of field names and sort directions
		 * @return TEntity|null The found entity or null if not found
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		public function findOneBy(array $searchData, ?array $sortBy = null): ?object {
			return $this->em()->findOneBy($this->entityClass, $searchData, $sortBy);
		}
	}