<?php

	namespace Quellabs\ObjectQuel\Persistence;

	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;

	/**
	 * Specialized persister class responsible for updating existing entities in the database
	 * This class handles the process of detecting and persisting changes to existing entities
	 */
	class UpdatePersister {

		/**
		 * Reference to the UnitOfWork that manages persistence operations
		 */
		private UnitOfWork $unitOfWork;

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
		 * Executes the generated `replace` statement (see persist()).
		 */
		private EntityManager $entityManager;

		/**
		 * Handles the post-update version readback (see persist()).
		 * @var VersionValueHandler
		 */
		private VersionValueHandler $valueHandler;

		/**
		 * UpdatePersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate update operations
		 */
		public function __construct(UnitOfWork $unitOfWork) {
			$this->unitOfWork = $unitOfWork;
			$this->entityStore = $unitOfWork->getEntityStore();
			$this->propertyHandler = $unitOfWork->getPropertyHandler();
			$this->entityManager = $unitOfWork->getEntityManager();
			$this->valueHandler = $unitOfWork->getVersionValueHandler();
		}

		/**
		 * Updates an entity by generating and executing a `replace <alias>
		 * (prop = :prop, ...) where <alias>.<pk> = :pk [and ...] [and
		 * <alias>.<version> = :origVersion]` statement. Dirty-checking and the
		 * optimistic-lock WHERE stay a persister concern; QuelToSQLReplace
		 * handles identifier quoting, value serialization, and the
		 * @Orm\Version bump.
		 * @param object $entity The entity to be updated in the database
		 * @return void
		 * @throws OrmException If the update fails or a version mismatch is detected
		 * @throws EntityResolutionException
		 */
		public function persist(object $entity): void {
			$metadata = $this->entityStore->getMetadata($entity);
			$serializedEntity = $this->unitOfWork->getSerializer()->serialize($entity);
			$originalData = $this->unitOfWork->getEntitySnapshot($entity);

			// If there's no original data, this entity was never loaded from the database.
			// Updating an untracked entity is a programming error.
			if ($originalData === null) {
				throw new OrmException(
					"Cannot update entity of type '" . get_class($entity) . "': no original data found. " .
					"The entity must be loaded from the database before it can be updated."
				);
			}

			$primaryKeyColumnNames = $metadata->identifierColumns;
			$versionColumns = $metadata->versionColumns;
			$versionColumnNames = array_column($versionColumns, 'name');

			$changedColumns = $this->extractChangedFields($serializedEntity, $originalData, $primaryKeyColumnNames, $versionColumnNames);

			$alias = 'e';
			$assignments = [];
			$parameters = [];

			foreach (array_keys($changedColumns) as $columnName) {
				$property = $metadata->getPropertyName($columnName);

				if ($property === null) {
					throw new \LogicException("Changed column '{$columnName}' has no mapped property on '{$metadata->className}'");
				}

				$paramName = "set_{$property}";
				$assignments[] = "{$property} = :{$paramName}";

				// Raw, not serialized — the compiler denormalizes it once.
				$parameters[$paramName] = $this->propertyHandler->get($entity, $property);
			}

			// The grammar requires at least one assignment — only unmet if
			// nothing changed besides identifier/version columns. A harmless
			// self-assignment satisfies it without altering the row.
			if (empty($assignments)) {
				$noopKey = $metadata->identifierKeys[0];
				$assignments[] = "{$noopKey} = :noop_{$noopKey}";
				$parameters["noop_{$noopKey}"] = $this->propertyHandler->get($entity, $noopKey);
			}

			$conditions = [];

			foreach ($metadata->identifierKeys as $index => $primaryKey) {
				$paramName = "pk{$index}";
				$conditions[] = "{$alias}.{$primaryKey} = :{$paramName}";
				$parameters[$paramName] = $this->propertyHandler->get($entity, $primaryKey);
			}

			// Against the original snapshot, not the live property — the
			// lock must check the value as loaded, not whatever it is now.
			foreach ($versionColumns as $versionProperty => $versionColumn) {
				$paramName = "origversion_{$versionProperty}";
				$conditions[] = "{$alias}.{$versionProperty} = :{$paramName}";
				$parameters[$paramName] = $originalData[$versionColumn['name']];
			}

			$quel = "range of {$alias} is {$metadata->className} replace {$alias} (" . implode(', ', $assignments) . ') where ' . implode(' and ', $conditions);

			try {
				$result = $this->entityManager->executeQuery($quel, $parameters);
			} catch (QuelException $e) {
				throw new OrmException($e->getMessage(), $e->getCode(), $e);
			}

			if ($result === null) {
				throw new \LogicException('replace returned no QuelResult');
			}

			// 0 rows affected means either:
			// 1. The record was deleted by another process, or
			// 2. The version number changed (concurrent modification - race condition)
			if ($result->getAffectedRows() === 0) {
				if (empty($versionColumnNames)) {
					$message = "Update failed: the row no longer exists (it may have been deleted by another process).";
				} else {
					$version = json_encode(array_intersect_key($originalData, array_flip($versionColumnNames)));
					$message = "Optimistic lock conflict: the entity was modified concurrently. Expected version: {$version}";
				}

				throw new OrmException($message);
			}

			$primaryKeyValues = [];

			foreach ($metadata->identifierKeys as $index => $primaryKey) {
				$primaryKeyValues[$primaryKeyColumnNames[$index]] = $this->propertyHandler->get($entity, $primaryKey);
			}

			$fetchedDatetimeValues = $this->valueHandler->fetchUpdatedVersionValues(
				$metadata->tableName,
				$versionColumns,
				$primaryKeyColumnNames,
				$primaryKeyValues,
			);

			$this->valueHandler->updateEntityVersionValues($entity, $fetchedDatetimeValues);
		}

		/**
		 * Extracts only the fields that have changed compared to the original entity data
		 * Version columns are excluded as they are handled separately
		 * @param array<string, mixed> $serializedEntity Current entity state as column => value pairs
		 * @param array<string, mixed> $originalData Original entity snapshot
		 * @param array<int, string> $primaryKeyColumnNames Primary key column names
		 * @param array<int, string> $versionColumnNames Version column names to exclude
		 * @return array<string, mixed> Changed fields as column => value pairs
		 */
		protected function extractChangedFields(array $serializedEntity, array $originalData, array $primaryKeyColumnNames, array $versionColumnNames): array {
			// Create a list of changed fields by comparing current values with original values
			// We exclude version columns as they are handled separately with special auto-increment logic
			return array_filter($serializedEntity, function ($value, $key) use ($originalData, $primaryKeyColumnNames, $versionColumnNames) {
				// Skip version tagged columns. These will be handled separately
				if (in_array($key, $versionColumnNames)) {
					return false;
				}

				// Skip primary key columns; they identify the row, not data to update
				if (in_array($key, $primaryKeyColumnNames)) {
					return false;
				}

				// If the snapshot doesn't have this key at all, treat it as changed rather
				// than risk silently dropping a real change due to an undefined-index warning
				// or accidental null-coercion equality.
				if (!array_key_exists($key, $originalData)) {
					return true;
				}

				// Strict comparison avoids PHP's loose-equality pitfalls, e.g. "1" == 1,
				// 0 == false, and null == "" all evaluating true and masking real changes.
				return $value !== $originalData[$key];
			}, ARRAY_FILTER_USE_BOTH);
		}
	}
