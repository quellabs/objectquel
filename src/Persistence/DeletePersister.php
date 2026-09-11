<?php

	namespace Quellabs\ObjectQuel\Persistence;

	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;

	/**
	 * Specialized persister class responsible for handling entity deletion operations
	 * This class specifically manages the process of removing entities from the database
	 */
	class DeletePersister {

		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		private EntityStore $entityStore;

		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		private PropertyHandler $propertyHandler;

		/**
		 * Executes the generated `delete` statement (see persist()).
		 */
		private EntityManager $entityManager;

		/**
		 * DeletePersister constructor
		 * Initializes all necessary components for entity deletion operations
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate deletion operations
		 */
		public function __construct(UnitOfWork $unitOfWork) {
			$this->entityStore = $unitOfWork->getEntityStore();
			$this->propertyHandler = $unitOfWork->getPropertyHandler();
			$this->entityManager = $unitOfWork->getEntityManager();
		}

		/**
		 * Deletes an entity by generating and executing a `delete <alias>
		 * where <alias>.<pk> = :pk [and ...]` statement for its primary key.
		 * @param object $entity The entity to be removed from the database
		 * @throws OrmException If the DELETE operation fails
		 * @throws EntityResolutionException
		 */
		public function persist(object $entity): void {
			$alias = 'e';
			$metadata = $this->entityStore->getMetadata($entity);

			// WHERE clause: match the row by its primary key.
			$conditions = $this->buildPrimaryKeyConditions($entity, $metadata, $alias);

			// Compile the `delete` statement.
			$quel = "range of {$alias} is {$metadata->className} delete {$alias} where " . implode(' and ', $conditions->clauses);
			
			// Execute the `delete` statement
			$this->executeDelete($quel, $conditions->parameters);
		}

		/**
		 * Builds the WHERE conditions and parameters that identify the row
		 * to delete by its primary key.
		 * @param object $entity
		 * @param EntityMetadataRecord $metadata
		 * @param string $alias
		 * @return QuelFragment
		 */
		private function buildPrimaryKeyConditions(object $entity, EntityMetadataRecord $metadata, string $alias): QuelFragment {
			$conditions = [];
			$parameters = [];

			foreach ($metadata->identifierKeys as $index => $primaryKey) {
				$paramName = "pk{$index}";
				$conditions[] = "{$alias}.{$primaryKey} = :{$paramName}";

				// Raw, not serialized — the compiler denormalizes it once.
				$parameters[$paramName] = $this->propertyHandler->get($entity, $primaryKey);
			}

			return new QuelFragment($conditions, $parameters);
		}

		/**
		 * Executes the generated `delete` statement.
		 * @param string $quel
		 * @param array<string, mixed> $parameters
		 * @return void
		 * @throws OrmException
		 */
		private function executeDelete(string $quel, array $parameters): void {
			try {
				$this->entityManager->executeQuery($quel, $parameters);
			} catch (QuelException $e) {
				throw new OrmException($e->getMessage(), $e->getCode(), $e);
			}
		}
	}