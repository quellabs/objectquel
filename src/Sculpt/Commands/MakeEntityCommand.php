<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	/**
	 * Import required classes for entity management and console interaction
	 */
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * MakeEntityCommand - CLI command for creating or updating entity classes
	 *
	 * This command allows users to interactively create or update entity classes
	 * through a command-line interface, collecting properties with their types
	 * and constraints, including relationship definitions with primary key selection.
	 */
	class MakeEntityCommand extends CommandBase {
		
		/**
		 * Entity modifier service for handling entity creation/modification operations
		 * @var EntityModifier|null
		 */
		private ?EntityModifier $entityModifier = null;
		
		/**
		 * Entity store for handling entity metadata
		 * @var EntityStore|null
		 */
		private ?EntityStore $entityStore = null;
		
		/**
		 * @var Configuration
		 */
		private Configuration $configuration;
		
		/**
		 * MakeEntityCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ServiceProvider|null $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return "make:entity";
		}
		
		/**
		 * Get a short description of what the command does
		 * @return string Command description
		 */
		public function getDescription(): string {
			return "Create or update an entity class with properties and relationships";
		}
		
		/**
		 * Get detailed help information for the command
		 * @return string Command help text
		 */
		public function getHelp(): string {
			return "Creates or updates an entity class with standard properties and ORM relationship mappings.\n" .
				"Supported relationship types: OneToOne, OneToMany, ManyToOne.\n\n" .
				"Relationships can be established with specific primary key columns in the target entity.";
		}
		
		/**
		 * Execute the command
		 * @param ConfigurationManager $config Parameters passed to the command
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Get the entity name from user input
			$entityName = $this->input->ask("Class name of the entity to create or update (e.g. AgreeableElephant)");
			
			// Exit gracefully if no entity name provided
			if (empty($entityName)) {
				return 0;
			}
			
			// Display appropriate message based on whether entity exists
			$this->displayEntityOperationMessage($entityName);
			
			// Get all available entities for relationship selection
			$availableEntities = $this->getAvailableEntities();
			
			// Collect all property definitions from user
			$properties = $this->collectProperties($availableEntities, $entityName);
			
			// Create or update the entity if properties were defined
			if (!empty($properties)) {
				$this->getEntityModifier()->createOrUpdateEntity($entityName, $properties);
				$this->output->writeLn("Entity details written");
			}
			
			return 0;
		}
		
		/**
		 * Returns the EntityStore object
		 * @return EntityStore
		 */
		private function getEntityStore(): EntityStore {
			if ($this->entityStore === null) {
				$this->entityStore = new EntityStore($this->configuration);
			}
			
			return $this->entityStore;
		}
		
		/**
		 * Returns the EntityModifier object
		 * @return EntityModifier
		 */
		private function getEntityModifier(): EntityModifier {
			if ($this->entityModifier === null) {
				$this->entityModifier = new EntityModifier($this->configuration);
			}
			
			return $this->entityModifier;
		}
		
		/**
		 * Displays a message indicating whether a new entity will be created or an existing one updated
		 * @param string $entityName Base name of the entity
		 * @return void
		 */
		private function displayEntityOperationMessage(string $entityName): void {
			$entityNamePlus = $entityName . "Entity";
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if (!$this->getEntityModifier()->entityExists($entityNamePlus)) {
				$this->output->writeLn("\nCreating new entity: {$entityPath}/{$entityNamePlus}.php\n");
			} else {
				$this->output->writeLn("\nUpdating existing entity: {$entityPath}/{$entityNamePlus}.php\n");
			}
		}
		
		/**
		 * Scans the entity directory and returns a list of available entity names
		 * @return array List of entity names without the "Entity" suffix
		 */
		private function getAvailableEntities(): array {
			$entityPath = realpath($this->configuration->getEntityPath());
			$availableEntities = [];
			
			if (!is_dir($entityPath)) {
				return $availableEntities;
			}
			
			$files = scandir($entityPath);
			
			foreach ($files as $file) {
				// Skip directories and non-php files
				if (is_dir($entityPath . '/' . $file) || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
					continue;
				}
				
				// Extract entity name without "Entity" suffix and .php extension
				$entityFileName = pathinfo($file, PATHINFO_FILENAME);
				
				if (str_ends_with($entityFileName, 'Entity')) {
					$availableEntities[] = substr($entityFileName, 0, -6);
				}
			}
			
			return $availableEntities;
		}
		
		/**
		 * Interactively collects property definitions from the user
		 * @param array $availableEntities List of available entities for relationships
		 * @param string $entityName Name of the current entity being created/updated
		 * @return array Array of property definitions
		 */
		private function collectProperties(array $availableEntities, string $entityName): array {
			$properties = [];
			
			// Loop until user stops adding properties
			while (true) {
				// Get property name or break if empty
				$propertyName = $this->input->ask("New property name (press enter to stop adding fields)");
				
				if (empty($propertyName)) {
					break;
				}
				
				// Get property type from user
				$propertyType = $this->input->choice("\nField type", [
					'tinyinteger', 'smallinteger','integer', 'biginteger', 'string', 'char', 'text', 'float',
					'decimal', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'enum', 'relationship',
				]);
				
				// Handle relationship types separately
				if ($propertyType === 'relationship') {
					$relationshipProperties = $this->collectRelationshipProperties(
						$propertyName,
						$availableEntities,
						$entityName
					);
					$properties = array_merge($properties, $relationshipProperties);
					continue;
				}
				
				// Collect standard property definition
				$property = $this->collectStandardProperties($propertyName, $propertyType);
				$properties[] = $property;
			}
			
			return $properties;
		}
		
		/**
		 * Collects relationship property details and returns property definitions
		 * Includes both the relationship property and any auto-generated foreign key columns
		 * @param string $propertyName Name of the relationship property
		 * @param array $availableEntities List of available entities
		 * @param string $entityName Name of the current entity
		 * @return array Array of property definitions (may include multiple properties)
		 */
		private function collectRelationshipProperties(string $propertyName, array $availableEntities, string $entityName): array {
			$properties = [];
			
			// Get relationship type from user
			$relationshipType = $this->input->choice("\nRelationship type", [
				'OneToOne', 'OneToMany', 'ManyToOne'
			]);
			
			// Get target entity and referenced field information
			$targetInfo = $this->getTargetEntityAndReferenceField($availableEntities);
			$targetEntity = $targetInfo['targetEntity'];
			$referencedField = $targetInfo['referencedField'];
			$targetColumn = $targetInfo['targetColumn'];
			
			// Determine foreign key column details for owning side relationships
			$relationColumn = null;
			$fkColumnType = 'integer';
			$fkUnsigned = true;
			
			if ($relationshipType === 'ManyToOne' || $relationshipType === 'OneToOne') {
				// Derive join column from relation name + attached "Id"
				$relationColumn = $propertyName . "Id";
				
				// Introspect target entity to determine FK column type
				$fkInfo = $this->determineForeignKeyType($targetEntity, $referencedField);
				$fkColumnType = $fkInfo['type'];
				$fkUnsigned = $fkInfo['unsigned'];
			}
			
			// Get relationship mapping configuration based on type
			$mappingConfig = $this->collectRelationshipMapping($relationshipType, $entityName, $referencedField);
			
			// Determine PHP type for the property
			if (($relationshipType === 'OneToMany')) {
				$propertyPhpType = "CollectionInterface";
			} else {
				$propertyPhpType = $targetEntity . "Entity";
			}
			
			// Ask if the relationship can be null
			$relationshipNullable = $this->input->confirm(
				"\nAllow this relationship to be null?",
				$relationshipType === 'ManyToOne'
			);
			
			// Add the relationship property
			$properties[] = [
				"name"             => $propertyName,
				"type"             => $propertyPhpType,
				"relationshipType" => $relationshipType,
				"targetEntity"     => $targetEntity,
				"mappedBy"         => $mappingConfig['mappedBy'],
				"inversedBy"       => $mappingConfig['inversedBy'],
				"relationColumn"   => $relationColumn,
				"targetColumn"     => $targetColumn
			];
			
			// Auto-add foreign key column for owning side relationships
			if (
				($relationshipType === 'ManyToOne' || ($relationshipType === 'OneToOne' && $mappingConfig['mappedBy'] === null)) &&
				$relationColumn !== null
			) {
				$properties[] = [
					"name"     => $relationColumn,
					"type"     => $fkColumnType,
					"unsigned" => $fkUnsigned,
					"nullable" => $relationshipNullable,
				];
			}
			
			return $properties;
		}
		
		/**
		 * Determines the foreign key column type by introspecting the target entity
		 * @param string $targetEntity Name of the target entity
		 * @param string $referencedField Field name in the target entity
		 * @return array Associative array with 'type' and 'unsigned' keys
		 */
		private function determineForeignKeyType(string $targetEntity, string $referencedField): array {
			$result = [
				'type' => 'integer',
				'unsigned' => true
			];
			
			try {
				$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
				
				if ($this->getEntityStore()->exists($fullEntityName)) {
					// Get column definitions for the target entity
					$columnDefinitions = $this->getEntityStore()->extractEntityColumnDefinitions($fullEntityName);
					
					// Get the column map to find the database column name
					$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
					$referencedDbColumn = $columnMap[$referencedField] ?? null;
					
					// Look up the column type from the definitions
					if ($referencedDbColumn && isset($columnDefinitions[$referencedDbColumn])) {
						$result['type'] = $columnDefinitions[$referencedDbColumn]['type'];
						$result['unsigned'] = $columnDefinitions[$referencedDbColumn]['unsigned'] ?? true;
					}
				}
			} catch (\Exception $e) {
				// Fallback to integer if we can't determine type
				$this->output->writeLn("\nCouldn't determine FK type, defaulting to integer");
			}
			
			return $result;
		}
		
		/**
		 * Collects relationship mapping configuration (mappedBy/inversedBy) based on relationship type
		 * @param string $relationshipType Type of relationship (OneToOne, OneToMany, ManyToOne)
		 * @param string $entityName Name of the current entity
		 * @param string $referencedField Field name being referenced in the target entity
		 * @return array Associative array with 'mappedBy' and 'inversedBy' keys
		 */
		private function collectRelationshipMapping(string $relationshipType, string $entityName, string $referencedField): array {
			// OneToMany relationships are always the inverse side
			// They must reference the owning side's field via mappedBy
			if ($relationshipType === 'OneToMany') {
				$this->output->writeLn("\nUsing '{$referencedField}' as the mappedBy field in the related entity");
				return [
					'mappedBy' => $referencedField,
					'inversedBy' => null
				];
			}
			
			// ManyToOne relationships are always the owning side
			// They only need inversedBy if the relationship is bidirectional
			if ($relationshipType === 'ManyToOne') {
				$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
				
				// Unidirectional ManyToOne needs no mapping configuration
				if (!$bidirectional) {
					return [
						'mappedBy' => null,
						'inversedBy' => null
					];
				}
				
				// Bidirectional ManyToOne needs inversedBy to point to the collection field on the other side
				$suggestedCollectionName = lcfirst($entityName) . 's';
				$inversedBy = $this->input->ask(
					"\nInversedBy field name in the related entity",
					$suggestedCollectionName
				);
				
				return [
					'mappedBy' => null,
					'inversedBy' => $inversedBy
				];
			}
			
			// OneToOne relationships can be either owning or inverse side
			if ($relationshipType === 'OneToOne') {
				$isOwningSide = $this->input->confirm("\nIs this the owning side of the relationship?", true);
				
				// Inverse side must use mappedBy to reference the owning side's field
				if (!$isOwningSide) {
					$suggestedFieldName = lcfirst($entityName);
					$mappedBy = $this->input->ask(
						"\nMappedBy field name in the related entity",
						$suggestedFieldName
					);
					
					return [
						'mappedBy' => $mappedBy,
						'inversedBy' => null
					];
				}
				
				// Owning side only needs inversedBy if bidirectional
				$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
				
				// Unidirectional OneToOne from owning side needs no mapping configuration
				if (!$bidirectional) {
					return [
						'mappedBy' => null,
						'inversedBy' => null
					];
				}
				
				// Bidirectional OneToOne from owning side needs inversedBy to point to the inverse side's field
				$suggestedFieldName = lcfirst($entityName);
				$inversedBy = $this->input->ask(
					"\nInversedBy field name in the related entity",
					$suggestedFieldName
				);
				
				return [
					'mappedBy' => null,
					'inversedBy' => $inversedBy
				];
			}
			
			// Fallback for unknown relationship types
			return [
				'mappedBy' => null,
				'inversedBy' => null
			];
		}
		
		/**
		 * Collects details for a standard (non-relationship) property
		 * @param string $propertyName Name of the property
		 * @param string $propertyType Type of the property
		 * @return array Property definition array
		 */
		private function collectStandardProperties(string $propertyName, string $propertyType): array {
			$property = [
				"name" => $propertyName,
				"type" => $propertyType,
			];
			
			// Collect type-specific attributes
			if ($propertyType === 'string') {
				$property['limit'] = $this->input->ask("\nCharacter limit for this string field", "255");
			}
			
			if (in_array($propertyType, ['tinyinteger', 'smallinteger', 'integer', 'biginteger'])) {
				$property['unsigned'] = $this->input->confirm(
					"\nShould this number field store positive values only (unsigned)?",
					false
				);
			}
			
			if ($propertyType === 'decimal') {
				$decimalConfig = $this->collectDecimalConfiguration();
				$property['precision'] = $decimalConfig['precision'];
				$property['scale'] = $decimalConfig['scale'];
			}
			
			if ($propertyType === 'enum') {
				$property['enumType'] = $this->collectEnumType();
			}
			
			// Ask if property can be nullable
			$property['nullable'] = $this->input->confirm(
				"\nAllow this field to be empty/null in the database?",
				false
			);
			
			return $property;
		}
		
		/**
		 * Collects precision and scale configuration for decimal fields
		 * @return array Associative array with 'precision' and 'scale' keys
		 */
		private function collectDecimalConfiguration(): array {
			$precision = null;
			$scale = null;
			
			// Collect precision with validation
			while ($precision === null || $precision <= 0) {
				$precision = (int) $this->input->ask("\nPrecision (total digits, e.g. 10)?", 10);
				
				if ($precision <= 0) {
					$this->output->warning("Precision must be greater than 0.");
				}
			}
			
			// Collect scale with validation against precision
			while ($scale === null || $scale < 0 || $scale > $precision) {
				$scale = (int) $this->input->ask("\nScale (decimal digits, e.g. 2)?", 2);
				
				if ($scale < 0) {
					$this->output->warning("Scale cannot be negative.");
				} elseif ($scale > $precision) {
					$this->output->warning("Scale cannot be greater than precision ($precision).");
				}
			}
			
			return [
				'precision' => $precision,
				'scale' => $scale
			];
		}
		
		/**
		 * Collects and validates the enum type for enum fields
		 * @return string Fully qualified enum class name
		 */
		private function collectEnumType(): string {
			$enumType = null;
			
			while ($enumType === null) {
				// Ask for enum class name
				$enumType = $this->input->ask("Enter fully qualified enum class name (e.g. App\Enum\OrderStatus)");
				
				// Validate that the enum exists
				if (enum_exists($enumType)) {
					break;
				}
				
				// Show error if enum class doesn't exist
				$this->output->error("Invalid enum class name");
				$enumType = null;
			}
			
			return $enumType;
		}

		/**
		 * Gets the target entity and reference field information for a relationship
		 * @param array $availableEntities List of available entities
		 * @return array Associative array with targetEntity, referencedField, and targetColumn
		 */
		private function getTargetEntityAndReferenceField(array $availableEntities): array {
			// Set default values
			$result = [
				'targetEntity'         => '',
				'referencedField'      => 'id',
				'targetColumn' => 'id'
			];
			
			// Get the target entity (either from selection or manual entry)
			$result['targetEntity'] = $this->selectTargetEntity($availableEntities);
			
			// If we have a valid existing entity, get its primary key
			if (in_array($result['targetEntity'], $availableEntities)) {
				$referenceInfo = $this->getTargetEntityReferenceField($result['targetEntity']);
				$result['referencedField'] = $referenceInfo['field'];
				$result['targetColumn'] = $referenceInfo['column'];
			}
			
			return $result;
		}
		
		/**
		 * Asks the user to select a target entity from available options or enter one manually
		 * @param array $availableEntities List of available entities
		 * @return string Selected or entered entity name
		 */
		private function selectTargetEntity(array $availableEntities): string {
			// If no entities available, ask for manual entry
			if (empty($availableEntities)) {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			}
			
			// Allow selecting from list or manual entry
			$targetEntityOptions = array_merge($availableEntities, ['[Enter manually]']);
			$targetEntityChoice = $this->input->choice("\nSelect target entity", $targetEntityOptions);
			
			if ($targetEntityChoice === '[Enter manually]') {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			}
			
			return $targetEntityChoice;
		}
		
		/**
		 * Get primary key properties for an entity using EntityStore
		 * @param string $entityName Entity name without "Entity" suffix
		 * @return array Array of primary key property names
		 */
		public function getEntityPrimaryKeys(string $entityName): array {
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $entityName . 'Entity';
			
			// Use the EntityStore to get primary keys if possible
			if ($this->getEntityStore()->exists($fullEntityName)) {
				return $this->getEntityStore()->getIdentifierKeys($fullEntityName);
			}
			
			// Fallback to looking for 'id' property if EntityStore doesn't have the entity
			return ['id'];
		}
		
		/**
		 * Gets the reference field and column information for a target entity
		 * @param string $targetEntity Name of the target entity
		 * @return array Associative array with field and column names
		 */
		private function getTargetEntityReferenceField(string $targetEntity): array {
			// Get primary keys from the target entity
			$primaryKeys = $this->getEntityPrimaryKeys($targetEntity);
			
			// Default values
			$result = [
				'field'  => 'id',
				'column' => 'id'
			];
			
			if (empty($primaryKeys)) {
				$this->output->writeLn("\nNo primary keys found in target entity. Using 'id' as default.");
				return $result;
			}
			
			// If we have multiple primary keys, let the user choose
			if (count($primaryKeys) > 1) {
				$this->output->writeLn("\nMultiple primary keys found in target entity:");
				$result['field'] = $this->input->choice("\nSelect the primary key field to reference", $primaryKeys);
			} else {
				$result['field'] = $primaryKeys[0];
			}
			
			// Get the actual column name for the selected primary key
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
			$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
			$result['column'] = $columnMap[$result['field']] ?? $result['field'];
			
			$this->output->writeLn("\nUsing primary key: {$result['field']} (DB column: {$result['column']})");
			
			return $result;
		}
	}