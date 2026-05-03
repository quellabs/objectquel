<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyFactory;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\Support\Tools;
	
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
		 * Reference to the UnitOfWork that manages persistence operations
		 */
		private UnitOfWork $unitOfWork;
		
		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		private PropertyHandler $propertyHandler;
		
		/**
		 * Database connection adapter used for executing SQL queries
		 * Abstracts the underlying database system and provides a unified interface
		 */
		private DatabaseAdapter $connection;
		
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
		 * Handles values with @Orm\Version annotations
		 * @var VersionValueHandler
		 */
		private VersionValueHandler $valueHandler;
		
		/**
		 * InsertPersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate insertion operations
		 * @param PrimaryKeyFactory|null $factory Factory for creating primary keys
		 */
		public function __construct(UnitOfWork $unitOfWork, ?PrimaryKeyFactory $factory = null) {
			$this->unitOfWork = $unitOfWork;
			$this->entityManager = $unitOfWork->getEntityManager();
			$this->entityStore = $unitOfWork->getEntityStore();
			$this->propertyHandler = $unitOfWork->getPropertyHandler();
			$this->connection = $unitOfWork->getConnection();
			$this->valueHandler = new VersionValueHandler($unitOfWork->getConnection(), $unitOfWork->getEntityStore(), $unitOfWork, $unitOfWork->getPropertyHandler());
			$this->primaryKeyFactory = $factory ?? new PrimaryKeyFactory();
			$this->strategyColumnCache = [];
		}
		
		/**
		 * Persists (inserts) an entity into the database
		 * @param object $entity The entity to be inserted into the database
		 * @throws OrmException If the database query fails
		 * @throws AnnotationReaderException
		 */
		public function persist(object $entity): void {
			// Gather the necessary information for the insert operation
			// Get the table name where the entity should be stored
			$tableName = $this->entityStore->getOwningTable($entity);
			$tableNameEscaped = $this->valueHandler->escapeIdentifier($tableName);
			
			// Fetch the column map
			$columnMap = array_flip($this->entityStore->getColumnMap($entity));
			
			// Get the primary key property names and their corresponding column names
			$primaryKeys = $this->entityStore->getIdentifierKeys($entity);
			
			// Get the column names that make up the primary key
			$primaryKeyColumnNames = $this->entityStore->getIdentifierColumnNames($entity);
			
			// Iterate through each identified primary key for the entity
			foreach ($primaryKeys as $primaryKey) {
				// First check if the primary key already has a value
				// This prevents overwriting manually set primary keys
				$currentValue = $this->propertyHandler->get($entity, $primaryKey);
				
				// Only generate a new primary key if the current value is null or an empty string
				// This respects existing values while ensuring all primary keys have values
				if ($currentValue === null || $currentValue === '') {
					// Determine the primary key generation strategy for this specific primary key field
					// (e.g., 'uuid', 'identity', 'sequence') - only done when needed
					$strategy = $this->getPrimaryKeyStrategy($entity, $primaryKey);
					
					// Skip identity strategy - database handles these
					if ($strategy === 'identity') {
						continue;
					}
					
					// Generate a new primary key value using the appropriate generator
					// Passes context (entity manager and entity) for generators that need it
					$value = $this->primaryKeyFactory->generate($this->entityManager, $entity, $strategy);
					
					// Make sure the generator returned a valid value
					if ($value === null) {
						throw new OrmException("Primary key generator for strategy '{$strategy}' returned null for primary key '{$primaryKey}'");
					}
					
					// Update the entity with the newly generated primary key value
					// Uses the property handler to respect access rules for private/protected properties
					$this->propertyHandler->set($entity, $primaryKey, $value);
				}
			}
			
			// Get the primary key property names and their corresponding column names
			$versionColumns = $this->entityStore->getVersionColumns($entity);
			$versionColumnNames = array_flip(array_column($versionColumns, 'name'));
			
			// Serialize the entity into an array of column name => value pairs
			$serializedEntity = $this->unitOfWork->getSerializer()->serialize($entity);
			
			// If this entity is a Single-Table Inheritance subclass, inject the discriminator
			// column value so the INSERT always writes the correct type marker without the
			// entity needing to declare the column as a mapped property.
			$discriminatorInfo = $this->getDiscriminatorInfo($entity);
			
			if ($discriminatorInfo !== null) {
				$columnName         = $discriminatorInfo['column'];
				$discriminatorValue = $discriminatorInfo['value'];
				$serializedEntity[$columnName] = $discriminatorValue;
			}
			
			// Create the SQL query for insertion
			$sqlParts = [];
			
			foreach ($serializedEntity as $key => $value) {
				// Escape the identifier (add backticks)
				$escapedKey = $this->valueHandler->escapeIdentifier($key);
				
				// Check if the column name exists
				if (isset($versionColumnNames[$key])) {
					// Fetch the column name from the map
					$columnName = $columnMap[$key];
					
					// Fetch the value
					$initialVersion = $this->getInitialVersionValue($versionColumns[$columnName]["column"]->getType());
					
					// Remove version property from bound parameters list
					unset($serializedEntity[$key]);
					
					// Add initial value to SQL
					$sqlParts[] = $escapedKey . "=" . $initialVersion;
				} else {
					// normal bound parameter
					$sqlParts[] = $escapedKey . "=:" . $key;
				}
			}
			
			// Implode the parts
			$sql = implode(",", $sqlParts);
			
			// Execute the insert query with the serialized entity data as parameters
			$rs = $this->connection->Execute("INSERT INTO {$tableNameEscaped} SET {$sql}", $serializedEntity);
			
			// If the query fails, throw an exception with the error details
			if (!$rs) {
				throw new OrmException($this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}
			
			// After successful query execution, check if the entity has a primary key with identity/auto-increment strategy
			// This identifies columns marked either with @PrimaryKeyStrategy(strategy="identity") or primary keys with no strategy
			$autoincrementColumn = $this->entityStore->findAutoIncrementPrimaryKey($entity);
			
			if ($autoincrementColumn !== null) {
				// Entity has an identity primary key column that should receive the auto-generated ID from the database
				// Get the last inserted ID value from the database connection
				$autoIncrementId = $this->connection->getInsertId();
				
				// Check if the result is valid
				if ($autoIncrementId !== false && $autoIncrementId > 0) {
					// Non-zero ID was returned, indicating the database successfully generated a new primary key value
					// Update the entity's property with the database-generated ID
					// This ensures the entity's state is synchronized with its database representation
					$this->propertyHandler->set($entity, $autoincrementColumn, (int)$autoIncrementId);
					
					// Also set it in $serializedEntity
					$serializedEntity[$autoincrementColumn] = (int)$autoIncrementId;
				}
				
				// If the auto-increment ID is 0, it may indicate no new ID was generated
				// (possibly due to a transaction rollback or other database condition)
			}
			
			// Extract the primary key values from the original data
			// These will be used in the WHERE clause to identify the record to update
			$primaryKeyValues = array_intersect_key($serializedEntity, array_flip($primaryKeyColumnNames));
			
			// Fetch version values from the database (if any)
			$fetchedDatetimeValues = $this->valueHandler->fetchUpdatedVersionValues(
				$tableName,
				$versionColumns,
				$primaryKeyColumnNames,
				$primaryKeyValues,
			);
			
			// Update the entity with the new version values so the in-memory object
			// matches the database state and can be used for subsequent operations
			$this->valueHandler->updateEntityVersionValues($entity, $fetchedDatetimeValues);
		}
		
		/**
		 * Retrieves the primary key generation strategy for a given entity and primary key.
		 * @param object $entity The entity object to examine
		 * @param string $primaryKey The name of the primary key field
		 * @return string             The primary key strategy value
		 */
		protected function getPrimaryKeyStrategy(object $entity, string $primaryKey): string {
			// Fetch owning table
			$table = $this->entityStore->getOwningTable($entity);
			
			// Fetch key from cache if present
			if (isset($this->strategyColumnCache[$table][$primaryKey])) {
				return $this->strategyColumnCache[$table][$primaryKey];
			}
			
			// Get all annotations for the entity from the entity store
			$annotations = $this->entityStore->getAnnotations($entity);
			
			// If no annotations exist for the specified primary key, return "identity"
			if (empty($annotations[$primaryKey])) {
				return $this->strategyColumnCache[$table][$primaryKey] = "identity";
			}
			
			// Iterate through all annotations for the primary key
			foreach ($annotations[$primaryKey] as $annotation) {
				// Check if the current annotation is a PrimaryKeyStrategy instance
				if ($annotation instanceof PrimaryKeyStrategy) {
					// Return the value of the PrimaryKeyStrategy annotation
					return $this->strategyColumnCache[$table][$primaryKey] = $annotation->getValue();
				}
			}
			
			// No PrimaryKeyStrategy annotation found for this primary key
			return $this->strategyColumnCache[$table][$primaryKey] = "identity";
		}
		
		protected function getInitialVersionValue(string $columnType): \DateTime|int|string {
			switch ($columnType) {
				case 'int':
				case 'integer':
				case 'bigint':
					return 1;
				
				case 'datetime':
				case 'timestamp':
					return "NOW()";
				
				case 'uuid':
				case 'guid':
					return "'" . Tools::createUUIDv7() . "'";
				
				default:
					throw new \RuntimeException("Invalid column type {$columnType} for Version annotation");
			}
		}
		
		/**
		 * Resolves discriminator column name and value for STI subclasses.
		 *
		 * Returns null when the entity is not an STI subclass (the common case),
		 * so the caller can short-circuit with a simple null check.
		 * @param object $entity The entity being persisted
		 * @return array{column: string, value: string}|null
		 * @throws AnnotationReaderException
		 */
		protected function getDiscriminatorInfo(object $entity): ?array {
			// One call covers both the subclass and all parent class annotations
			$classAnnotations = $this->entityStore->getAnnotationReader()->getClassAnnotations(get_class($entity));
			$discriminatorValue = $classAnnotations[DiscriminatorValue::class] ?? null;
			$discriminatorColumn = $classAnnotations[DiscriminatorColumn::class] ?? null;
			
			// Not an STI subclass if either annotation is missing
			if ($discriminatorValue === null || $discriminatorColumn === null) {
				return null;
			}
			
			$value = $discriminatorValue->getValue();
			$columnName = $discriminatorColumn->getName();
			
			if ($value === '' || $columnName === '') {
				return null;
			}
			
			return ['column' => $columnName, 'value' => $value];
		}
	}