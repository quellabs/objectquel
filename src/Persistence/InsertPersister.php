<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
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
		 * Used to generate engine-appropriate SQL fragments (e.g. the correct
		 * "current datetime" expression) instead of hardcoding MySQL syntax.
		 * @var PlatformCapabilities
		 */
		private PlatformCapabilities $platformCapabilities;
		
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
			$this->platformCapabilities = $unitOfWork->getPlatformCapabilities();
			$this->valueHandler = $unitOfWork->getVersionValueHandler();
			$this->primaryKeyFactory = $factory ?? new PrimaryKeyFactory();
			$this->strategyColumnCache = [];
		}
		
		/**
		 * Persists (inserts) an entity into the database
		 * @param object $entity The entity to be inserted into the database
		 * @throws OrmException If the database query fails
		 * @throws QuelException
		 * @throws EntityResolutionException
		 * @throws \Exception
		 */
		public function persist(object $entity): void {
			// Get metadata
			$metadata = $this->entityStore->getMetadata($entity);
			$tableName = $metadata->tableName;
			$tableNameEscaped = $this->connection->escapeIdentifier($tableName);
			$columnMap = array_flip($metadata->columnMap);
			$primaryKeys = $metadata->identifierKeys;
			$primaryKeyColumnNames = $metadata->identifierColumns;
			
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
			$versionColumns = $metadata->versionColumns;
			$versionColumnNames = array_flip(array_column($versionColumns, 'name'));
			
			// Serialize the entity into an array of column name => value pairs
			$serializedEntity = $this->unitOfWork->getSerializer()->serialize($entity);
			
			// If this entity is a Single-Table Inheritance subclass, inject the discriminator
			// column value so the INSERT always writes the correct type marker without the
			// entity needing to declare the column as a mapped property.
			$discriminatorInfo = $this->getDiscriminatorInfo($entity);
			
			if ($discriminatorInfo !== null) {
				$columnName = $discriminatorInfo['column'];
				$discriminatorValue = $discriminatorInfo['value'];
				$serializedEntity[$columnName] = $discriminatorValue;
			}
			
			// Create the SQL query for insertion.
			// Uses portable INSERT INTO table (col1, col2) VALUES (val1, val2) syntax
			// rather than MySQL/MariaDB-only "INSERT INTO table SET col=val" syntax,
			// so this works unmodified against PostgreSQL, SQLite, and SQL Server too.
			$columnParts = [];
			$valueParts = [];
			
			// Copy first, then mutate the copy below — $serializedEntity itself stays
			// untouched so the foreach over it isn't iterating an array it's also modifying.
			$boundParameters = $serializedEntity;
			
			foreach ($serializedEntity as $key => $value) {
				// Escape the identifier (add backticks)
				$escapedKey = $this->connection->escapeIdentifier($key);
				$columnParts[] = $escapedKey;
				
				// Check if the column name exists
				if (isset($versionColumnNames[$key])) {
					// Fetch the column name from the map
					$columnName = $columnMap[$key];
					
					// Fetch the value
					$initialVersion = $this->getInitialVersionValue($versionColumns[$columnName]["column"]->getType());
					
					// Remove version column from the bound parameters, since its value is
					// inlined as a raw SQL expression below rather than bound — it has no
					// :placeholder in the query, so PDO would reject an unused named parameter.
					unset($boundParameters[$key]);
					
					// Initial version values are raw SQL expressions (e.g. NOW(), a UUID
					// literal, or a bare integer), not bound parameters, so they go
					// directly into the VALUES list rather than as a :placeholder.
					$valueParts[] = $initialVersion;
				} else {
					// normal bound parameter
					$valueParts[] = ":" . $key;
				}
			}
			
			// Implode the parts
			$columnList = implode(",", $columnParts);
			$valueList = implode(",", $valueParts);
			
			// Execute the insert query with the bound parameters (version columns excluded)
			$rs = $this->connection->Execute("INSERT INTO {$tableNameEscaped} ({$columnList}) VALUES ({$valueList})", $boundParameters);
			
			// If the query fails, throw an exception with the error details
			if (!$rs) {
				throw new OrmException($this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}
			
			// After successful query execution, check if the entity has a primary key with identity/auto-increment strategy
			// This identifies columns marked either with @PrimaryKeyStrategy(strategy="identity") or primary keys with no strategy
			if ($metadata->autoIncrementColumn !== null) {
				// Entity has an identity primary key column that should receive the auto-generated ID from the database
				// Get the last inserted ID value from the database connection
				$autoIncrementId = $this->connection->getInsertId();
				
				// Check if the result is valid
				if ($autoIncrementId !== false && $autoIncrementId > 0) {
					// Non-zero ID was returned, indicating the database successfully generated a new primary key value
					// Update the entity's property with the database-generated ID
					// This ensures the entity's state is synchronized with its database representation
					$this->propertyHandler->set($entity, $metadata->autoIncrementColumn, (int)$autoIncrementId);
					
					// Also set it in $serializedEntity
					$serializedEntity[$metadata->autoIncrementColumn] = (int)$autoIncrementId;
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
		
		/**
		 * Returns the initial version column for new entities
		 * @param string $columnType
		 * @return int|string
		 * @throws \Exception
		 */
		protected function getInitialVersionValue(string $columnType): int|string {
			/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
			switch ($columnType) {
				case 'int':
				case 'integer':
				case 'bigint':
					return 1;
				
				case 'datetime':
				case 'timestamp':
					// Use the engine-appropriate "current datetime" expression rather
					// than hardcoding MySQL's NOW() — SQLite and SQL Server use
					// different syntax for this.
					return $this->platformCapabilities->getCurrentDatetimeFunction();
				
				case 'uuid':
				case 'guid':
					return "'" . Tools::createUUIDv7() . "'";
				
				default:
					throw new \RuntimeException("Invalid column type {$columnType} for Version annotation");
			}
		}
		
		/**
		 * Resolves the discriminator column name and value for STI (Single Table Inheritance) subclasses.
		 *
		 * Both @DiscriminatorValue and @DiscriminatorColumn must be present with non-empty values
		 * for STI persistence to function. Either annotation may be defined on the class itself or
		 * on any ancestor — getClassAnnotations() walks the full inheritance chain.
		 *
		 * Returns null when the class is not participating in STI (the common case), allowing
		 * the caller to short-circuit with a simple null check.
		 *
		 * @param object $entity The entity being persisted.
		 * @return array{column: non-empty-string, value: non-empty-string}|null
		 *     Null if the entity is not an STI subclass.
		 * @throws AnnotationReaderException If annotation metadata cannot be read.
		 * @throws \InvalidArgumentException If STI annotations are present but contain empty values,
		 * @throws QuelException
		 *     indicating a misconfigured entity class.
		 */
		protected function getDiscriminatorInfo(object $entity): ?array {
			// Fetch class annotations
			$classAnnotations = $this->entityStore
				->getAnnotationReader()
				->getClassAnnotations(get_class($entity));
			
			// Retrieve DiscriminatorValue and DiscriminatorColumn
			$discriminatorValue = $classAnnotations[DiscriminatorValue::class] ?? null;
			$discriminatorColumn = $classAnnotations[DiscriminatorColumn::class] ?? null;
			
			// Both annotations must be present for this to be a valid STI subclass.
			// If neither is defined, this is a regular (non-STI) entity — not an error.
			if (
				!$discriminatorValue instanceof DiscriminatorValue ||
				!$discriminatorColumn instanceof DiscriminatorColumn
			) {
				return null;
			}
			
			// Fetch the discriminator values
			$value = $discriminatorValue->getValue();
			$columnName = $discriminatorColumn->getName();
			
			// Annotations exist but have empty values — this is a configuration error,
			// not a "not an STI entity" situation. Fail loudly rather than silently
			// returning null and causing a hard-to-trace persistence bug downstream.
			if ($value === '' || $columnName === '') {
				throw new QuelException(sprintf(
					'Entity "%s" has STI annotations but %s is empty. Check your @DiscriminatorValue and @DiscriminatorColumn definitions.',
					get_class($entity),
					$value === '' ? '@DiscriminatorValue' : '@DiscriminatorColumn'
				));
			}
			
			// Return the values
			return [
				'column' => $columnName,
				'value'  => $value
			];
		}
	}