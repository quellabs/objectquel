<?php

	namespace Quellabs\ObjectQuel\Persistence;

	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyFactory;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;

	/**
	 * Specialized persister class responsible for inserting new entities into the database
	 * This class handles the creation process of inserting entities into database tables
	 */
	class InsertPersister {

		/**
		 * Reference to the EntityManager
		 * @var EntityManager
		 */
		private EntityManager $entityManager;

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
		 * Factory for creating primary key values
		 * @var PrimaryKeyFactory
		 */
		private PrimaryKeyFactory $primaryKeyFactory;

		/**
		 * @var array<string, array<string, string>> Cache for primary key strategy fetcher
		 */
		private array $strategyColumnCache;

		/**
		 * Handles the post-insert version readback (see persist()).
		 * @var VersionValueHandler
		 */
		private VersionValueHandler $valueHandler;

		/**
		 * InsertPersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate insertion operations
		 * @param PrimaryKeyFactory|null $factory Factory for creating primary keys
		 */
		public function __construct(UnitOfWork $unitOfWork, ?PrimaryKeyFactory $factory = null) {
			$this->entityManager = $unitOfWork->getEntityManager();
			$this->entityStore = $unitOfWork->getEntityStore();
			$this->propertyHandler = $unitOfWork->getPropertyHandler();
			$this->valueHandler = $unitOfWork->getVersionValueHandler();
			$this->primaryKeyFactory = $factory ?? new PrimaryKeyFactory();
			$this->strategyColumnCache = [];
		}

		/**
		 * Inserts an entity by generating and executing an `append to <alias>
		 * (prop = :prop, ...)` statement. QuelToSQLAppend handles SQL text,
		 * identifier quoting, @Orm\Version initialization, and STI
		 * discriminator injection; this method keeps only what the generated
		 * statement can't express: primary-key generation and post-insert
		 * readback onto the live entity.
		 * @param object $entity The entity to be inserted into the database
		 * @throws OrmException If the insert fails
		 * @throws QuelException
		 * @throws EntityResolutionException
		 * @throws \Exception
		 */
		public function persist(object $entity): void {
			$metadata = $this->entityStore->getMetadata($entity);
			$primaryKeys = $metadata->identifierKeys;
			$primaryKeyColumnNames = $metadata->identifierColumns;

			// Generate non-identity PK values up front (composite keys
			// included) — append's own auto-generation only handles a
			// single-column key.
			foreach ($primaryKeys as $primaryKey) {
				$currentValue = $this->propertyHandler->get($entity, $primaryKey);

				if ($currentValue === null || $currentValue === '') {
					$strategy = $this->getPrimaryKeyStrategy($entity, $primaryKey);

					if ($strategy === 'identity') {
						continue;
					}

					$value = $this->primaryKeyFactory->generate($this->entityManager, $entity, $strategy);

					if ($value === null) {
						throw new OrmException("Primary key generator for strategy '{$strategy}' returned null for primary key '{$primaryKey}'");
					}

					$this->propertyHandler->set($entity, $primaryKey, $value);
				}
			}

			$alias = 'e';
			$assignments = [];
			$parameters = [];

			foreach (array_keys($metadata->columnMap) as $property) {
				// QuelToSQLAppend auto-initializes an unassigned version column.
				if ($metadata->isVersioned($property)) {
					continue;
				}

				$value = $this->propertyHandler->get($entity, $property);

				// Unset here only for an identity-strategy PK, left for the
				// database to assign — every other PK was generated above.
				if ($metadata->isIdentifierKey($property) && ($value === null || $value === '')) {
					continue;
				}

				$assignments[] = "{$property} = :{$property}";

				// Raw, not serialized — the compiler denormalizes it once.
				$parameters[$property] = $value;
			}

			// The grammar requires at least one assignment — only unmet for
			// an entity whose sole mapped column is an identity PK. Bind it
			// as NULL, equivalent to omitting it for an auto-increment column.
			if (empty($assignments)) {
				if ($metadata->autoIncrementColumn === null) {
					throw new \LogicException("append to '{$metadata->className}' has no columns to insert");
				}

				$assignments[] = "{$metadata->autoIncrementColumn} = :{$metadata->autoIncrementColumn}";
				$parameters[$metadata->autoIncrementColumn] = null;
			}

			$quel = "range of {$alias} is {$metadata->className} append to {$alias} (" . implode(', ', $assignments) . ')';

			try {
				$result = $this->entityManager->executeQuery($quel, $parameters);
			} catch (QuelException $e) {
				throw new OrmException($e->getMessage(), $e->getCode(), $e);
			}

			if ($result === null) {
				throw new \LogicException('append returned no QuelResult for a literal-values insert');
			}

			if ($metadata->autoIncrementColumn !== null) {
				$generatedId = $result->getGeneratedId();

				if (is_numeric($generatedId) && (int)$generatedId > 0) {
					$this->propertyHandler->set($entity, $metadata->autoIncrementColumn, (int)$generatedId);
				}
			}

			$primaryKeyValues = [];

			foreach ($primaryKeys as $index => $primaryKey) {
				$primaryKeyValues[$primaryKeyColumnNames[$index]] = $this->propertyHandler->get($entity, $primaryKey);
			}

			$fetchedDatetimeValues = $this->valueHandler->fetchUpdatedVersionValues(
				$metadata->tableName,
				$metadata->versionColumns,
				$primaryKeyColumnNames,
				$primaryKeyValues,
			);

			$this->valueHandler->updateEntityVersionValues($entity, $fetchedDatetimeValues);
		}

		/**
		 * Retrieves the primary key generation strategy for a given entity and primary key.
		 * @param object $entity The entity object to examine
		 * @param string $primaryKey The name of the primary key field
		 * @return string             The primary key strategy value
		 * @throws EntityResolutionException
		 */
		protected function getPrimaryKeyStrategy(object $entity, string $primaryKey): string {
			$metadata = $this->entityStore->getMetadata($entity);

			// Fetch key from cache if present
			if (isset($this->strategyColumnCache[$metadata->tableName][$primaryKey])) {
				return $this->strategyColumnCache[$metadata->tableName][$primaryKey];
			}

			// Get all annotations for the entity from the entity store
			$annotations = $metadata->getAnnotations();

			// If no annotations exist for the specified primary key, return "identity"
			if (empty($annotations[$primaryKey])) {
				return $this->strategyColumnCache[$metadata->tableName][$primaryKey] = "identity";
			}

			// Iterate through all annotations for the primary key
			foreach ($annotations[$primaryKey] as $annotation) {
				// Check if the current annotation is a PrimaryKeyStrategy instance
				if ($annotation instanceof PrimaryKeyStrategy) {
					// Return the value of the PrimaryKeyStrategy annotation
					return $this->strategyColumnCache[$metadata->tableName][$primaryKey] = $annotation->getValue();
				}
			}

			// No PrimaryKeyStrategy annotation found for this primary key
			return $this->strategyColumnCache[$metadata->tableName][$primaryKey] = "identity";
		}
	}
