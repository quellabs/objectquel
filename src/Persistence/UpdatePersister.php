<?php

	namespace Quellabs\ObjectQuel\Persistence;

	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
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
			$originalData = $this->unitOfWork->getEntitySnapshot($entity);

			// Bail out early if this entity was never loaded from the database.
			$this->assertHasSnapshot($entity, $originalData);

			$alias = 'e';
			$versionColumnNames = array_column($metadata->versionColumns, 'name');

			// Diff live entity state against its snapshot to find what actually changed.
			$changedColumns = $this->extractChangedFields(
				$this->unitOfWork->getSerializer()->serialize($entity),
				$originalData,
				$metadata->identifierColumns,
				$versionColumnNames
			);

			// SET clause: the changed columns. WHERE clause: primary key + optimistic-lock version check.
			$assignments = $this->buildAssignments($metadata, $entity, $changedColumns);
			$conditions = $this->buildLockConditions($metadata, $entity, $originalData, $alias);
			$parameters = $assignments->parameters + $conditions->parameters;

			// Compile and run the `replace` statement.
			$quel = "range of {$alias} is {$metadata->className} replace {$alias} (" . implode(', ', $assignments->clauses) . ') where ' . implode(' and ', $conditions->clauses);
			$result = $this->executeReplace($quel, $parameters);

			// Zero affected rows means the row vanished or the lock was lost — fail loudly instead of silently no-op-ing.
			$this->assertRowUpdated($result, $originalData, $versionColumnNames);

			// Pull any DB-generated version value (e.g. updated_at) back onto the entity.
			$this->readBackVersionValues($entity, $metadata);
		}

		/**
		 * Guards against updating an entity that was never loaded from the database.
		 * @param object $entity
		 * @param array|null $originalData
		 * @return void
		 * @throws OrmException
		 */
		private function assertHasSnapshot(object $entity, ?array $originalData): void {
			if ($originalData === null) {
				throw new OrmException(
					"Cannot update entity of type '" . get_class($entity) . "': no original data found. " .
					"The entity must be loaded from the database before it can be updated."
				);
			}
		}

		/**
		 * Builds the `prop = :param` assignment list and its bound parameters for the
		 * changed columns. Falls back to a harmless self-assignment when nothing
		 * changed, since the grammar requires at least one assignment.
		 * @param object $entity
		 * @param array<string, mixed> $changedColumns Changed fields as column => value pairs
		 */
		private function buildAssignments(EntityMetadataRecord $metadata, object $entity, array $changedColumns): QuelFragment {
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

			return new QuelFragment($assignments, $parameters);
		}

		/**
		 * Builds the WHERE conditions and parameters that identify the row and
		 * enforce the optimistic-lock check against the original snapshot.
		 * @param object $entity
		 * @param array<string, mixed> $originalData
		 */
		private function buildLockConditions(EntityMetadataRecord $metadata, object $entity, array $originalData, string $alias): QuelFragment {
			$conditions = [];
			$parameters = [];

			foreach ($metadata->identifierKeys as $index => $primaryKey) {
				$paramName = "pk{$index}";
				$conditions[] = "{$alias}.{$primaryKey} = :{$paramName}";
				$parameters[$paramName] = $this->propertyHandler->get($entity, $primaryKey);
			}

			// Against the original snapshot, not the live property — the
			// lock must check the value as loaded, not whatever it is now.
			foreach ($metadata->versionColumns as $versionProperty => $versionColumn) {
				$paramName = "origversion_{$versionProperty}";
				$conditions[] = "{$alias}.{$versionProperty} = :{$paramName}";
				$parameters[$paramName] = $originalData[$versionColumn['name']];
			}

			return new QuelFragment($conditions, $parameters);
		}

		/**
		 * Executes the generated `replace` statement.
		 * @param array<string, mixed> $parameters
		 * @throws OrmException
		 */
		private function executeReplace(string $quel, array $parameters): QuelResult {
			try {
				$result = $this->entityManager->executeQuery($quel, $parameters);
			} catch (QuelException $e) {
				throw new OrmException($e->getMessage(), $e->getCode(), $e);
			}

			if ($result === null) {
				throw new \LogicException('replace returned no QuelResult');
			}

			return $result;
		}

		/**
		 * Detects a lost update: zero rows affected means either the row was
		 * deleted by another process, or the version column no longer matches
		 * the snapshot (concurrent modification).
		 * @param array<string, mixed> $originalData
		 * @param array<int, string> $versionColumnNames
		 * @throws OrmException
		 */
		private function assertRowUpdated(QuelResult $result, array $originalData, array $versionColumnNames): void {
			if ($result->getAffectedRows() !== 0) {
				return;
			}

			if (empty($versionColumnNames)) {
				$message = "Update failed: the row no longer exists (it may have been deleted by another process).";
			} else {
				$version = json_encode(array_intersect_key($originalData, array_flip($versionColumnNames)));
				$message = "Optimistic lock conflict: the entity was modified concurrently. Expected version: {$version}";
			}

			throw new OrmException($message);
		}

		/**
		 * Re-fetches and applies any database-generated version values (e.g.
		 * updated_at) onto the live entity after a successful update.
		 */
		private function readBackVersionValues(object $entity, EntityMetadataRecord $metadata): void {
			$primaryKeyValues = [];

			foreach ($metadata->identifierKeys as $index => $primaryKey) {
				$primaryKeyValues[$metadata->identifierColumns[$index]] = $this->propertyHandler->get($entity, $primaryKey);
			}

			$fetchedDatetimeValues = $this->valueHandler->fetchUpdatedVersionValues(
				$metadata->tableName,
				$metadata->versionColumns,
				$metadata->identifierColumns,
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
