<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\Support\StringInflector;
	
	/**
	 * CLI command for creating or updating entity classes with properties and relationships.
	 *
	 * Supports standard data types (string, integer, decimal, etc.) and ORM relationships
	 * (OneToOne, OneToMany, ManyToOne) with automatic foreign key generation.
	 */
	class MakeEntityCommand extends CommandBase {
		
		/** @var EntityModifier|null Lazy-loaded entity modifier for creating/updating entity files */
		private ?EntityModifier $entityModifier = null;
		
		/** @var EntityStore|null Lazy-loaded entity store for metadata access */
		private ?EntityStore $entityStore = null;
		
		/** @var Configuration ORM configuration instance */
		private Configuration $configuration;
		
		/**
		 * Constructor
		 * @param ConsoleInput $input Console input handler
		 * @param ConsoleOutput $output Console output handler
		 * @param ServiceProvider|null $provider Service provider containing configuration
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Get the command signature used to invoke this command.
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return "make:entity";
		}
		
		/**
		 * Get a short description of what this command does.
		 * @return string Command description
		 */
		public function getDescription(): string {
			return "Create or update an entity class with properties and relationships";
		}
		
		/**
		 * Get detailed help text for this command.
		 * @return string Help text
		 */
		public function getHelp(): string {
			return "Creates or updates an entity class with standard properties and ORM relationship mappings.\n" .
				"Supported relationship types: OneToOne, OneToMany, ManyToOne.";
		}
		
		/**
		 * Execute the command - prompts for entity name and properties, then generates/updates the entity class.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			$entityName = $this->input->ask("Class name of the entity to create or update (e.g. AgreeableElephant)");
			
			if (empty($entityName)) {
				return 0;
			}
			
			$this->displayEntityOperationMessage($entityName);
			
			$availableEntities = $this->getAvailableEntities();
			$properties = $this->collectProperties($availableEntities, $entityName);
			
			if (!empty($properties)) {
				$this->getEntityModifier()->createOrUpdateEntity($entityName, $properties);
				$this->output->writeLn("Entity details written");
			}
			
			return 0;
		}
		
		/**
		 * Lazy-load EntityStore instance.
		 * @return EntityStore Entity store instance
		 */
		private function getEntityStore(): EntityStore {
			return $this->entityStore ??= new EntityStore($this->configuration);
		}
		
		/**
		 * Lazy-load EntityModifier instance.
		 * @return EntityModifier Entity modifier instance
		 */
		private function getEntityModifier(): EntityModifier {
			return $this->entityModifier ??= new EntityModifier($this->configuration);
		}
		
		/**
		 * Display whether we're creating a new entity or updating an existing one.
		 * @param string $entityName Name of the entity (without 'Entity' suffix)
		 */
		private function displayEntityOperationMessage(string $entityName): void {
			$entityNamePlus = $entityName . "Entity";
			$entityPath = realpath($this->configuration->getEntityPath());
			$action = $this->getEntityModifier()->entityExists($entityNamePlus) ? "Updating existing" : "Creating new";
			
			$this->output->writeLn("\n{$action} entity: {$entityPath}/{$entityNamePlus}.php\n");
		}
		
		/**
		 * Scan entity directory and return list of existing entity names (without 'Entity' suffix).
		 * @return array List of entity names
		 */
		private function getAvailableEntities(): array {
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if (!is_dir($entityPath)) {
				return [];
			}
			
			$entities = [];
			foreach (scandir($entityPath) as $file) {
				if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
					continue;
				}
				
				$entityFileName = pathinfo($file, PATHINFO_FILENAME);
				if (str_ends_with($entityFileName, 'Entity')) {
					$entities[] = substr($entityFileName, 0, -6);
				}
			}
			
			return $entities;
		}
		
		/**
		 * Interactively collect all properties for the entity.
		 * Loops until user presses enter without entering a property name.
		 * @param array $availableEntities List of available entity names
		 * @param string $entityName Name of the current entity being created
		 * @return array Array of property definitions
		 */
		private function collectProperties(array $availableEntities, string $entityName): array {
			$properties = [];
			
			while (true) {
				$propertyName = $this->input->ask("New property name (press enter to stop adding fields)");
				
				if (empty($propertyName)) {
					break;
				}
				
				$propertyType = $this->input->choice("\nField type", [
					'tinyinteger', 'smallinteger', 'integer', 'biginteger', 'string', 'char', 'text', 'float',
					'decimal', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'enum', 'relationship',
				]);
				
				if ($propertyType === 'relationship') {
					$relationshipProperties = $this->collectRelationshipProperties($availableEntities, $entityName);
					$properties = array_merge($properties, $relationshipProperties);
				} else {
					$properties[] = $this->collectStandardProperty($propertyName, $propertyType);
				}
			}
			
			return $properties;
		}
		
		/**
		 * Collect configuration for a relationship property.
		 * Returns array of properties (relationship + FK column if applicable).
		 * @param array $availableEntities List of available entity names
		 * @param string $entityName Name of the current entity being created
		 * @return array Array of property definitions for the relationship
		 */
		private function collectRelationshipProperties(array $availableEntities, string $entityName): array {
			$relationshipType = $this->input->choice("\nRelationship type", ['OneToOne', 'OneToMany', 'ManyToOne']);
			
			$targetInfo = $this->getTargetEntityInfo($availableEntities);
			$propertyName = $this->generateRelationshipPropertyName($relationshipType, $targetInfo['targetEntity']);
			
			// Determine FK details for owning side
			$isOwningSide = in_array($relationshipType, ['ManyToOne', 'OneToOne']);
			$relationColumn = $isOwningSide ? $propertyName . "Id" : null;
			$fkInfo = $isOwningSide ? $this->determineForeignKeyType($targetInfo['targetEntity'], $targetInfo['referencedField']) : null;
			
			// Get bidirectional mapping configuration
			$mappingConfig = $this->collectRelationshipMapping(
				$relationshipType,
				$entityName,
				$targetInfo['targetEntity']
			);
			
			$propertyPhpType = ($relationshipType === 'OneToMany') ? "CollectionInterface" : $targetInfo['targetEntity'] . "Entity";
			$nullable = $this->input->confirm("\nAllow this relationship to be null?", $relationshipType === 'ManyToOne');
			
			$properties = [];
			
			// Add relationship property
			$properties[] = [
				"name"             => $propertyName,
				"type"             => $propertyPhpType,
				"relationshipType" => $relationshipType,
				"targetEntity"     => $targetInfo['targetEntity'],
				"mappedBy"         => $mappingConfig['mappedBy'],
				"inversedBy"       => $mappingConfig['inversedBy'],
				"relationColumn"   => $relationColumn,
				"foreignColumn"    => $targetInfo['foreignColumn'],
				"nullable"         => $nullable,
				"readonly"         => false
			];
			
			// Add FK column for owning side
			if ($isOwningSide && $relationColumn !== null && $mappingConfig['mappedBy'] === null) {
				$properties[] = [
					"name"     => $relationColumn,
					"type"     => $fkInfo['type'],
					"unsigned" => $fkInfo['unsigned'],
					"nullable" => $nullable,
					"readonly" => true
				];
			}
			
			// Create inverse/owning side in target entity if requested
			if ($mappingConfig['createInTarget'] ?? false) {
				$this->createRelationshipInTargetEntity(
					$targetInfo['targetEntity'],
					$entityName,
					$mappingConfig['targetPropertyName'],
					$mappingConfig['targetRelationType'],
					$mappingConfig['targetInversedBy'] ?? null,
					$targetInfo['foreignColumn']
				);
			}
			
			return $properties;
		}
		
		/**
		 * Generate conventional property name based on relationship type.
		 * @param string $relationshipType Type of relationship (OneToOne, OneToMany, ManyToOne)
		 * @param string $targetEntity Target entity name
		 * @return string Generated property name
		 */
		private function generateRelationshipPropertyName(string $relationshipType, string $targetEntity): string {
			if (($relationshipType === 'OneToMany')) {
				return StringInflector::pluralize(lcfirst($targetEntity));
			} else {
				return lcfirst($targetEntity);
			}
		}
		
		/**
		 * Collect bidirectional mapping configuration for a relationship.
		 * Returns mappedBy/inversedBy values and whether to create property in target entity.
		 *
		 * @param string $relationshipType Type of relationship (OneToOne, OneToMany, ManyToOne)
		 * @param string $entityName Name of the current entity
		 * @param string $targetEntity Name of the target entity
		 * @return array Mapping configuration with 'mappedBy', 'inversedBy', and optional creation flags
		 */
		private function collectRelationshipMapping(string $relationshipType, string $entityName, string $targetEntity): array {
			// OneToMany: always inverse side
			if ($relationshipType === 'OneToMany') {
				return $this->handleInverseSideMapping($targetEntity, $entityName, 'ManyToOne');
			}
			
			// OneToOne: ask which side is owning
			if ($relationshipType === 'OneToOne' && !$this->input->confirm("\nIs this the owning side?", true)) {
				return $this->handleInverseSideMapping($targetEntity, $entityName, 'OneToOne');
			}
			
			// ManyToOne or OneToOne (owning side)
			return $this->handleOwningSideMapping($relationshipType, $entityName, $targetEntity);
		}
		
		/**
		 * Handle mapping for the inverse side of a relationship.
		 * @param string $targetEntity Name of the target entity (contains the owning side)
		 * @param string $currentEntity Name of the current entity (inverse side)
		 * @param string $targetRelationType Relationship type on the target side (ManyToOne or OneToOne)
		 * @return array Mapping configuration
		 */
		private function handleInverseSideMapping(string $targetEntity, string $currentEntity, string $targetRelationType): array {
			// Try to find existing owning side property
			$mappedBy = $this->findRelationshipProperty($targetEntity, $currentEntity);
			
			if ($mappedBy !== null) {
				$this->output->writeLn("\nFound existing property '{$mappedBy}' in {$targetEntity}Entity");
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			// Offer to create owning side property
			$createTarget = $this->input->confirm(
				"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
				true
			);
			
			if (!$createTarget) {
				$mappedBy = $this->input->ask(
					"\nProperty name in {$targetEntity}Entity (you'll need to create it manually)",
					lcfirst($currentEntity)
				);
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			$targetPropertyName = $this->input->ask("\nNew property name inside {$targetEntity}Entity", lcfirst($currentEntity));
			
			if (($targetRelationType === 'ManyToOne')) {
				$inversedBy = StringInflector::pluralize(lcfirst($currentEntity));
			} else {
				$inversedBy = lcfirst($currentEntity);
			}
			
			return [
				'mappedBy'           => $targetPropertyName,
				'inversedBy'         => null,
				'createInTarget'     => true,
				'targetPropertyName' => $targetPropertyName,
				'targetRelationType' => $targetRelationType,
				'targetInversedBy'   => $inversedBy
			];
		}
		
		/**
		 * Handle mapping for the owning side of a relationship.
		 * @param string $relationshipType Type of relationship (ManyToOne or OneToOne)
		 * @param string $entityName Name of the current entity (owning side)
		 * @param string $targetEntity Name of the target entity
		 * @return array Mapping configuration
		 */
		private function handleOwningSideMapping(string $relationshipType, string $entityName, string $targetEntity): array {
			$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
			
			if (!$bidirectional) {
				return ['mappedBy' => null, 'inversedBy' => null];
			}
			
			if (($relationshipType === 'ManyToOne')) {
				$inversedBy = StringInflector::pluralize(lcfirst($entityName));
			} else {
				$inversedBy = lcfirst($entityName);
			}
			
			// Offer to create inverse side in target entity
			$createTarget = $this->input->confirm(
				"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
				true
			);
			
			if ($createTarget) {
				$targetRelationType = ($relationshipType === 'ManyToOne') ? 'OneToMany' : 'OneToOne';
				
				return [
					'mappedBy'           => null,
					'inversedBy'         => $inversedBy,
					'createInTarget'     => true,
					'targetPropertyName' => $inversedBy,
					'targetRelationType' => $targetRelationType,
					'targetInversedBy'   => null
				];
			}
			
			return ['mappedBy' => null, 'inversedBy' => $inversedBy];
		}
		
		/**
		 * Create a relationship property in the target entity.
		 * @param string $targetEntity Name of the target entity
		 * @param string $currentEntity Name of the current entity
		 * @param string $propertyName Name of the property to create
		 * @param string $relationshipType Type of relationship (OneToOne, OneToMany, ManyToOne)
		 * @param string|null $inversedBy Property name for the inverse side (if bidirectional)
		 * @param string $foreignColumn Database column name being referenced
		 */
		private function createRelationshipInTargetEntity(
			string  $targetEntity,
			string  $currentEntity,
			string  $propertyName,
			string  $relationshipType,
			?string $inversedBy,
			string  $foreignColumn
		): void {
			if (!$this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				$this->output->warning(
					"\nTarget entity '{$targetEntity}Entity' doesn't exist yet. " .
					"Create it first, then add the relationship."
				);
				return;
			}
			
			$properties = [];
			$isOwningSide = in_array($relationshipType, ['ManyToOne', 'OneToOne']);
			$phpType = ($relationshipType === 'OneToMany') ? "CollectionInterface" : $currentEntity . "Entity";
			
			// Determine mappedBy for inverse side
			$mappedBy = $isOwningSide ? null : lcfirst($currentEntity);
			
			// Add relationship property
			$properties[] = [
				"name"             => $propertyName,
				"type"             => $phpType,
				"relationshipType" => $relationshipType,
				"targetEntity"     => $currentEntity,
				"mappedBy"         => $mappedBy,
				"inversedBy"       => $inversedBy,
				"relationColumn"   => $isOwningSide ? $propertyName . "Id" : null,
				"foreignColumn"    => $foreignColumn,
				"nullable"         => true,
				"readonly"         => false
			];
			
			// Add FK column for owning side
			if ($isOwningSide) {
				$fkInfo = $this->determineForeignKeyType($currentEntity, $this->getEntityPrimaryKeys($currentEntity)[0]);
				
				$properties[] = [
					"name"     => $propertyName . "Id",
					"type"     => $fkInfo['type'],
					"unsigned" => $fkInfo['unsigned'],
					"nullable" => true,
					"readonly" => true
				];
			}
			
			$this->getEntityModifier()->createOrUpdateEntity($targetEntity, $properties);
			$this->output->writeLn("\nAdded property '{$propertyName}' to {$targetEntity}Entity");
		}
		
		/**
		 * Find an existing property in target entity that references the current entity.
		 * Returns property name if found, null otherwise.
		 * @param string $targetEntity Name of the target entity to search in
		 * @param string $currentEntity Name of the current entity being referenced
		 * @return string|null Property name if found, null otherwise
		 */
		private function findRelationshipProperty(string $targetEntity, string $currentEntity): ?string {
			$fullTargetEntity = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
			
			if (!$this->getEntityStore()->exists($fullTargetEntity)) {
				return null;
			}
			
			$reflectionClass = new \ReflectionClass($fullTargetEntity);
			$currentEntityFullName = $this->configuration->getEntityNameSpace() . '\\' . $currentEntity . 'Entity';
			$matchingProperties = [];
			
			foreach ($reflectionClass->getProperties() as $property) {
				$propertyType = $property->getType();
				
				if (!$propertyType instanceof \ReflectionNamedType) {
					continue;
				}
				
				$typeName = $propertyType->getName();
				
				if ($typeName === $currentEntityFullName || $typeName === $currentEntity . 'Entity') {
					$matchingProperties[] = $property->getName();
				}
			}
			
			if (count($matchingProperties) === 1) {
				return $matchingProperties[0];
			}
			
			if (count($matchingProperties) > 1) {
				return $this->input->choice("\nMultiple properties found. Select the owning side property:", $matchingProperties);
			}
			
			return null;
		}
		
		/**
		 * Collect configuration for a standard (non-relationship) property.
		 * @param string $propertyName Name of the property
		 * @param string $propertyType Type of the property (string, integer, decimal, etc.)
		 * @return array Property definition array
		 */
		private function collectStandardProperty(string $propertyName, string $propertyType): array {
			$property = [
				"name"     => $propertyName,
				"type"     => $propertyType,
				"readonly" => false
			];
			
			// String length limit
			if ($propertyType === 'string') {
				$property['limit'] = $this->input->ask("\nCharacter limit for this string field", "255");
			}
			
			// Integer unsigned flag
			if (in_array($propertyType, ['tinyinteger', 'smallinteger', 'integer', 'biginteger'])) {
				$property['unsigned'] = $this->input->confirm(
					"\nShould this number field store positive values only (unsigned)?",
					false
				);
			}
			
			// Decimal precision/scale
			if ($propertyType === 'decimal') {
				$decimalConfig = $this->collectDecimalConfiguration();
				$property['precision'] = $decimalConfig['precision'];
				$property['scale'] = $decimalConfig['scale'];
			}
			
			// Enum type
			if ($propertyType === 'enum') {
				$property['enumType'] = $this->collectEnumType();
			}
			
			$property['nullable'] = $this->input->confirm("\nAllow this field to be empty/null in the database?", false);
			
			return $property;
		}
		
		/**
		 * Collect and validate decimal precision and scale configuration.
		 * @return array Array with 'precision' and 'scale' keys
		 */
		private function collectDecimalConfiguration(): array {
			$precision = null;
			
			while ($precision === null || $precision <= 0) {
				$precision = (int)$this->input->ask("\nPrecision (total digits, e.g. 10)?", 10);
				
				if ($precision <= 0) {
					$this->output->warning("Precision must be greater than 0.");
				}
			}
			
			$scale = null;
			
			while ($scale === null || $scale < 0 || $scale > $precision) {
				$scale = (int)$this->input->ask("\nScale (decimal digits, e.g. 2)?", 2);
				
				if ($scale < 0) {
					$this->output->warning("Scale cannot be negative.");
				} elseif ($scale > $precision) {
					$this->output->warning("Scale cannot be greater than precision ($precision).");
				}
			}
			
			return ['precision' => $precision, 'scale' => $scale];
		}
		
		/**
		 * Collect and validate enum class name.
		 * @return string Fully qualified enum class name
		 */
		private function collectEnumType(): string {
			while (true) {
				$enumType = $this->input->ask("Enter fully qualified enum class name (e.g. App\Enum\OrderStatus)");
				
				if (enum_exists($enumType)) {
					return $enumType;
				}
				
				$this->output->error("Invalid enum class name");
			}
		}
		
		/**
		 * Get target entity name and its primary key information.
		 * @param array $availableEntities List of available entity names
		 * @return array Array with 'targetEntity', 'referencedField', and 'foreignColumn' keys
		 */
		private function getTargetEntityInfo(array $availableEntities): array {
			$targetEntity = $this->selectTargetEntity($availableEntities);
			
			// If entity doesn't exist yet, assume default 'id' column
			if (!in_array($targetEntity, $availableEntities)) {
				return [
					'targetEntity'    => $targetEntity,
					'referencedField' => 'id',
					'foreignColumn'   => 'id'
				];
			}
			
			// Get actual primary key info
			$primaryKeys = $this->getEntityPrimaryKeys($targetEntity);
			$primaryKeyField = $primaryKeys[0] ?? 'id';
			
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
			$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
			
			return [
				'targetEntity'    => $targetEntity,
				'referencedField' => $primaryKeyField,
				'foreignColumn'   => $columnMap[$primaryKeyField] ?? $primaryKeyField
			];
		}
		
		/**
		 * Prompt user to select target entity from available entities or enter manually.
		 * @param array $availableEntities List of available entity names
		 * @return string Selected entity name
		 */
		private function selectTargetEntity(array $availableEntities): string {
			if (empty($availableEntities)) {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			}
			
			$options = array_merge($availableEntities, ['[Enter manually]']);
			$choice = $this->input->choice("\nSelect target entity", $options);
			
			if ($choice === '[Enter manually]') {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			} else {
				return $choice;
			}
		}
		
		/**
		 * Get primary key field names for an entity.
		 * @param string $entityName Name of the entity (without 'Entity' suffix)
		 * @return array Array of primary key field names
		 */
		public function getEntityPrimaryKeys(string $entityName): array {
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $entityName . 'Entity';
			
			if ($this->getEntityStore()->exists($fullEntityName)) {
				return $this->getEntityStore()->getIdentifierKeys($fullEntityName);
			} else {
				return ['id'];
			}
		}
		
		/**
		 * Determine the column type and unsigned flag for a foreign key based on the referenced column.
		 * @param string $targetEntity Name of the target entity
		 * @param string $referencedField Name of the referenced field in the target entity
		 * @return array Array with 'type' and 'unsigned' keys
		 */
		private function determineForeignKeyType(string $targetEntity, string $referencedField): array {
			$result = ['type' => 'integer', 'unsigned' => true];
			
			try {
				$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
				
				if (!$this->getEntityStore()->exists($fullEntityName)) {
					return $result;
				}
				
				$columnDefinitions = $this->getEntityStore()->extractEntityColumnDefinitions($fullEntityName);
				$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
				$referencedDbColumn = $columnMap[$referencedField] ?? null;
				
				if ($referencedDbColumn && isset($columnDefinitions[$referencedDbColumn])) {
					$result['type'] = $columnDefinitions[$referencedDbColumn]['type'];
					$result['unsigned'] = $columnDefinitions[$referencedDbColumn]['unsigned'] ?? true;
				}
			} catch (\Exception $e) {
				$this->output->writeLn("\nCouldn't determine FK type, defaulting to integer");
			}
			
			return $result;
		}
	}