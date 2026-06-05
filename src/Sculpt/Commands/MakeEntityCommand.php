<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
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
	 * (OneToOne, InverseOf, ManyToOne) with automatic foreign key generation.
	 *
	 * @phpstan-import-type PhinxColumnType from SculptTypes
	 * @phpstan-import-type BaseProperty from SculptTypes
	 * @phpstan-import-type EnumProperty from SculptTypes
	 * @phpstan-import-type RelationProperty from SculptTypes
	 * @phpstan-import-type OrmRelationshipType from SculptTypes
	 * @phpstan-import-type RelationshipMappingConfig from SculptTypes
	 * @phpstan-import-type PropertyDefinition from SculptTypes
	 * @phpstan-import-type ColumnDefinitionRecord from EntityMetadataRecord
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
		 * @param ServiceProvider $provider Service provider containing configuration
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
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
				"Supported relationship types: OneToOne, InverseOf, ManyToOne.";
		}
		
		/**
		 * Execute the command - prompts for entity name and properties, then generates/updates the entity class.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Allow passing the entity name directly on the command line (e.g. `sculpt make:entity Elephant`),
			// falling back to an interactive prompt if omitted
			$entityName = $config->getPositional(0);
			
			if (!is_string($entityName) || $entityName === "") {
				$entityName = $this->input->ask("Class name of the entity to create or update (e.g. AgreeableElephant)") ?? '';
			}
			
			// Show message that we are making a new or modifying an exising entiy
			$this->displayEntityOperationMessage($entityName);
			
			// Scan the entity directory so relationship prompts can offer existing entities as choices
			$availableEntities = $this->getAvailableEntities();
			$properties = $this->collectProperties($availableEntities, $entityName);
			
			if (!empty($properties)) {
				$this->validateProperties($properties);
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
			$modifier = $this->getEntityModifier();
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if ($modifier->entityExists($entityName . "Entity")) {
				$displayName = $entityName . "Entity";
				$action = "Updating existing";
			} elseif ($modifier->entityExists($entityName)) {
				$displayName = $entityName;
				$action = "Updating existing";
			} else {
				$displayName = $entityName . "Entity"; // default for new files
				$action = "Creating new";
			}
			
			$this->output->writeLn("\n{$action} entity: {$entityPath}/{$displayName}.php\n");
		}
		
		/**
		 * Scan entity directory and return list of existing entity names (without 'Entity' suffix).
		 * @return string[] List of entity names
		 */
		private function getAvailableEntities(): array {
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if ($entityPath === false || !is_dir($entityPath)) {
				return [];
			}
			
			$entities = [];
			foreach (scandir($entityPath) as $file) {
				if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
					continue;
				}
				
				$entityFileName = pathinfo($file, PATHINFO_FILENAME);
				
				// Support both ElephantEntity.php and Elephant.php
				if (str_ends_with($entityFileName, 'Entity')) {
					$entities[] = substr($entityFileName, 0, -6);
				} else {
					$entities[] = $entityFileName;
				}
			}
			
			return $entities;
		}
		
		/**
		 * Interactively collect all properties for the entity.
		 * Loops until user presses enter without entering a property name.
		 * @param string[] $availableEntities List of available entity names
		 * @param string $entityName Name of the current entity being created
		 * @return array<int, PropertyDefinition> Array of property definitions
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
					$properties = array_merge($properties, $this->collectRelationshipProperties(
						$propertyName,
						$availableEntities,
						$entityName
					));
				} else {
					/** @var PhinxColumnType|'enum' $propertyType */
					$properties[] = $this->collectStandardProperty($propertyName, $propertyType);
				}
			}
			
			return $properties;
		}
		
		/**
		 * Build a relationship property definition.
		 * @param string $propertyName Property name on the entity
		 * @param string $phpType PHP type for the property (e.g. "OrderEntity" or "CollectionInterface")
		 * @param OrmRelationshipType $relationshipType ORM relationship type
		 * @param string $targetEntity Name of the related entity (without "Entity" suffix)
		 * @param string|null $relation Property name on the owning side that points to this entity (inverse side only)
		 * @param string|null $referencedColumn Property name on the inverse side (owning side only)
		 * @param string|null $relationColumn FK column name on this entity's table, or null for inverse sides
		 * @param bool $nullable Whether the relationship allows null
		 * @return RelationProperty
		 */
		private function buildRelationshipProperty(
			string $propertyName,
			string $phpType,
			string $relationshipType,
			string $targetEntity,
			?string $relation,
			?string $referencedColumn,
			?string $relationColumn,
			bool $nullable
		): array {
			return [
				"name"             => $propertyName,
				"type"             => $phpType,
				"relationshipType" => $relationshipType,
				"targetEntity"     => $targetEntity,
				"via"              => $relation,
				"inversedBy"       => $referencedColumn,
				"relationColumn"   => $relationColumn,
				"nullable"         => $nullable,
				"readonly"         => false
			];
		}
		
		/**
		 * Build a foreign key column property definition.
		 * FK columns are always readonly because they are managed by the ORM through
		 * the relationship property, not set directly by application code.
		 * @param string $columnName Column name (e.g. "orderId")
		 * @param PhinxColumnType $type Column type matching the referenced PK (e.g. "integer", "biginteger")
		 * @param bool $unsigned Whether the column is unsigned (should match the referenced PK)
		 * @param bool $nullable Whether the column allows null
		 * @return BaseProperty
		 */
		private function buildForeignKeyProperty(
			string $columnName,
			string $type,
			bool $unsigned,
			bool $nullable
		): array {
			return [
				"name"     => $columnName,
				"type"     => $type,
				"unsigned" => $unsigned,
				"nullable" => $nullable,
				"readonly" => true
			];
		}
		
		/**
		 * Collect configuration for a relationship property.
		 * Returns array of properties (relationship + FK column if applicable).
		 * @param string[] $availableEntities List of available entity names
		 * @param string $entityName Name of the current entity being created
		 * @return array<int, PropertyDefinition> Array of property definitions for the relationship
		 */
		private function collectRelationshipProperties(string $propertyName, array $availableEntities, string $entityName): array {
			/** @var OrmRelationshipType $relationshipType */
			$relationshipType = $this->input->choice("\nRelationship type", ['OneToOne', 'InverseOf', 'ManyToOne']);
			
			$targetInfo = $this->getTargetEntityInfo($availableEntities);
			
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
			
			$propertyPhpType = ($relationshipType === 'InverseOf') ? "CollectionInterface" : $targetInfo['targetEntity'] . "Entity";
			$nullable = $this->input->confirm("\nAllow this relationship to be null?", $relationshipType === 'ManyToOne');
			
			// Start with the relationship property itself
			$properties = [
				$this->buildRelationshipProperty(
					$propertyName,
					$propertyPhpType,
					$relationshipType,
					$targetInfo['targetEntity'],
					$mappingConfig['via'],
					$mappingConfig['inversedBy'],
					$relationColumn,
					$nullable
				)
			];
			
			// Add FK column for owning side, but only when this is not the inverse side of a
			// bidirectional relationship (via !== null means we're the inverse/non-owning side,
			// so the FK lives in the other table and we must not generate a column here)
			if ($isOwningSide && $relationColumn !== null && $mappingConfig['via'] === null && $fkInfo !== null) {
				$properties[] = $this->buildForeignKeyProperty($relationColumn, $fkInfo['type'], $fkInfo['unsigned'], $nullable);
			}
			
			// Create inverse/owning side in target entity if requested
			if (($mappingConfig['createInTarget'] ?? false) === true) {
				$this->createRelationshipInTargetEntity(
					$targetInfo['targetEntity'],
					$entityName,
					$mappingConfig['targetPropertyName'],
					$mappingConfig['targetRelationType'],
					$propertyName
				);
			}
			
			return $properties;
		}
		
		/**
		 * Collect bidirectional mapping configuration for a relationship.
		 * Returns via/inversedBy values and whether to create property in target entity.
		 * The return type is a union: when createInTarget is absent/false the optional keys are
		 * absent; when createInTarget is true they are always present, so PHPStan won't flag
		 * offsetAccess.notFound at the call site.
		 * @param string $relationshipType Type of relationship (OneToOne, InverseOf, ManyToOne)
		 * @param string $entityName Name of the current entity
		 * @param string $targetEntity Name of the target entity
		 * @return RelationshipMappingConfig
		 */
		private function collectRelationshipMapping(string $relationshipType, string $entityName, string $targetEntity): array {
			// InverseOf: always inverse side
			if ($relationshipType === 'InverseOf') {
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
		 * @param OrmRelationshipType $targetRelationType Relationship type on the target side (ManyToOne or OneToOne)
		 * @return RelationshipMappingConfig
		 */
		private function handleInverseSideMapping(string $targetEntity, string $currentEntity, string $targetRelationType): array {
			// Try to find existing owning side property
			$via = $this->findRelationshipProperty($targetEntity, $currentEntity);
			
			if ($via !== null) {
				$this->output->writeLn("\nFound existing property '{$via}' in {$targetEntity}Entity");
				return ['via' => $via, 'inversedBy' => null];
			}
			
			// Offer to create owning side property
			$createTarget = $this->input->confirm(
				"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
				true
			);
			
			if (!$createTarget) {
				$via = $this->input->ask(
					"\nProperty name in {$targetEntity}Entity (you'll need to create it manually)",
					lcfirst($currentEntity)
				);
				return ['via' => $via, 'inversedBy' => null];
			}
			
			$targetPropertyName = $this->input->ask("\nNew property name inside {$targetEntity}Entity", lcfirst($currentEntity));
			
			// For ManyToOne the inversedBy on the owning side points to the plural collection property;
			// for OneToOne it points to the single scalar property on the inverse side
			if (($targetRelationType === 'ManyToOne')) {
				$inversedBy = StringInflector::pluralize(lcfirst($currentEntity));
			} else {
				$inversedBy = lcfirst($currentEntity);
			}
			
			return [
				'via'                => $targetPropertyName,
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
		 * @return RelationshipMappingConfig
		 */
		private function handleOwningSideMapping(string $relationshipType, string $entityName, string $targetEntity): array {
			$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
			
			if (!$bidirectional) {
				return ['via' => null, 'inversedBy' => null];
			}
			
			// inversedBy on the owning side names the property on the inverse entity:
			// ManyToOne → inverse holds a collection, so pluralize; OneToOne → inverse holds a scalar
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
				// ManyToOne owning side → InverseOf inverse side; OneToOne stays OneToOne
				$targetRelationType = ($relationshipType === 'ManyToOne') ? 'InverseOf' : 'OneToOne';
				
				return [
					'via'                => null,
					'inversedBy'         => $inversedBy,
					'createInTarget'     => true,
					'targetPropertyName' => $inversedBy,
					'targetRelationType' => $targetRelationType,
					'targetInversedBy'   => null
				];
			}
			
			return ['via' => null, 'inversedBy' => $inversedBy];
		}
		
		/**
		 * Create a relationship property in the target entity.
		 * @param string $targetEntity Name of the target entity
		 * @param string $currentEntity Name of the current entity
		 * @param string $propertyName Name of the property to create
		 * @param OrmRelationshipType $relationshipType Type of relationship
		 * @param string|null $referencedColumn Property name for the inverse side (if bidirectional)
		 * @throws EntityResolutionException
		 */
		private function createRelationshipInTargetEntity(
			string $targetEntity,
			string $currentEntity,
			string $propertyName,
			string $relationshipType,
			?string $referencedColumn
		): void {
			if (!$this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				$this->output->warning(
					"\nTarget entity '{$targetEntity}Entity' doesn't exist yet. " .
					"Create it first, then add the relationship."
				);
				return;
			}
			
			$isOwningSide = in_array($relationshipType, ['ManyToOne', 'OneToOne']);
			$phpType = ($relationshipType === 'InverseOf') ? "CollectionInterface" : $currentEntity . "Entity";
			
			// For InverseOf (inverse side), via should reference the property name
			// in the owning entity that we're creating the inverse for.
			// The $inversedBy parameter contains the ManyToOne property name (e.g., "post")
			$relation = $isOwningSide ? null : $referencedColumn;
			
			// Start with the relationship property
			$properties = [
				$this->buildRelationshipProperty(
					$propertyName,
					$phpType,
					$relationshipType,
					$currentEntity,
					$relation,
					null,  // Inverse side doesn't have inversedBy
					$isOwningSide ? $propertyName . "Id" : null,
					true
				)
			];
			
			// Add FK column when this side owns the relationship (holds the FK in its table)
			if ($isOwningSide) {
				$fkInfo = $this->determineForeignKeyType($currentEntity, $this->getEntityPrimaryKeys($currentEntity)[0]);
				$properties[] = $this->buildForeignKeyProperty($propertyName . "Id", $fkInfo['type'], $fkInfo['unsigned'], true);
			}
			
			$this->validateProperties($properties);
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
			
			if (!class_exists($fullTargetEntity)) {
				return null;
			}
			
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
				
				// Match both fully qualified (from autoloader) and short form (not yet loaded)
				if ($typeName === $currentEntityFullName || $typeName === $currentEntity . 'Entity') {
					$matchingProperties[] = $property->getName();
				}
			}
			
			if (count($matchingProperties) === 1) {
				return $matchingProperties[0];
			}
			
			// Multiple candidates means the target entity has more than one property pointing
			// to the same entity (e.g. author + reviewer both → UserEntity); ask the user
			// which one is the owning side of this particular relationship
			if (count($matchingProperties) > 1) {
				return $this->input->choice("\nMultiple properties found. Select the owning side property:", $matchingProperties);
			}
			
			return null;
		}
		
		/**
		 * Collect configuration for a standard (non-relationship) property.
		 * @param string $propertyName Name of the property
		 * @param PhinxColumnType|'enum' $propertyType Type of the property
		 * @return BaseProperty|EnumProperty
		 */
		private function collectStandardProperty(string $propertyName, string $propertyType): array {
			// Enum is handled separately: its shape (EnumProperty) differs structurally from
			// BaseProperty and must be returned independently so PHPStan can verify each branch.
			if ($propertyType === 'enum') {
				return [
					"name"     => $propertyName,
					"type"     => 'enum',
					"enumType" => $this->collectEnumType(),
					"nullable" => $this->input->confirm("\nAllow this field to be empty/null in the database?", false),
					"readonly" => false,
				];
			}
			
			$property = [
				"name"     => $propertyName,
				"type"     => $propertyType,
				"readonly" => false
			];
			
			// String length limit
			if ($propertyType === 'string') {
				$property['limit'] = (int)($this->input->ask("\nCharacter limit for this string field", "255") ?? "255");
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
			
			$property['nullable'] = $this->input->confirm("\nAllow this field to be empty/null in the database?", false);
			
			return $property;
		}
		
		/**
		 * Collect and validate decimal precision and scale configuration.
		 * @return array{precision:int, scale:int}
		 */
		private function collectDecimalConfiguration(): array {
			$precision = null;
			
			while ($precision === null || $precision <= 0) {
				$precision = (int)$this->input->ask("\nPrecision (total digits, e.g. 10)?", "10");
				
				if ($precision <= 0) {
					$this->output->warning("Precision must be greater than 0.");
				}
			}
			
			$scale = null;
			
			while ($scale === null || $scale < 0 || $scale > $precision) {
				$scale = (int)$this->input->ask("\nScale (decimal digits, e.g. 2)?", "2");
				
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
				
				if ($enumType !== null && enum_exists($enumType)) {
					return $enumType;
				}
				
				$this->output->error("Invalid enum class name");
			}
		}
		
		/**
		 * Get target entity name and its primary key information.
		 * @param string[] $availableEntities List of available entity names
		 * @return array{
		 *     targetEntity: string,
		 *     referencedField: string
		 * }
		 * @throws EntityResolutionException
		 */
		private function getTargetEntityInfo(array $availableEntities): array {
			$targetEntity = $this->selectTargetEntity($availableEntities);
			
			// If entity doesn't exist yet, assume default 'id' column
			if (!in_array($targetEntity, $availableEntities)) {
				return [
					'targetEntity'    => $targetEntity,
					'referencedField' => 'id'
				];
			}
			
			// Get actual primary key info
			$primaryKeys = $this->getEntityPrimaryKeys($targetEntity);
			$primaryKeyField = $primaryKeys[0] ?? 'id';
			
			return [
				'targetEntity'    => $targetEntity,
				'referencedField' => $primaryKeyField
			];
		}
		
		/**
		 * Prompt user to select target entity from available entities or enter manually.
		 * @param string[] $availableEntities List of available entity names
		 * @return string Selected entity name
		 */
		private function selectTargetEntity(array $availableEntities): string {
			if (empty($availableEntities)) {
				do {
					$answer = $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
				} while ($answer === null || $answer === '');
				
				return $answer;
			}
			
			$options = array_merge($availableEntities, ['[Enter manually]']);
			$choice = $this->input->choice("\nSelect target entity", $options);
			
			if ($choice === '[Enter manually]') {
				do {
					$answer = $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
				} while ($answer === null || $answer === '');
				
				return $answer;
			} else {
				return $choice;
			}
		}
		
		/**
		 * Get primary key field names for an entity.
		 * @param string $entityName Name of the entity (without 'Entity' suffix)
		 * @return string[] Array of primary key field names
		 * @throws EntityResolutionException
		 */
		public function getEntityPrimaryKeys(string $entityName): array {
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $entityName . 'Entity';
			
			if (!$this->getEntityStore()->exists($fullEntityName)) {
				return ['id'];
			}
			
			$metadata = $this->getEntityStore()->getMetadata($fullEntityName);
			return $metadata->identifierKeys;
		}
		
		/**
		 * Determine the column type and unsigned flag for a foreign key based on the referenced column.
		 * @param string $targetEntity Name of the target entity
		 * @param string $referencedField Name of the referenced field in the target entity
		 * @return array{type: PhinxColumnType, unsigned: bool}
		 */
		private function determineForeignKeyType(string $targetEntity, string $referencedField): array {
			// Default to unsigned integer, which covers the most common auto-increment PK case
			/** @var array{type: PhinxColumnType, unsigned: bool} $result */
			$result = ['type' => 'integer', 'unsigned' => true];
			
			try {
				// Assemble the full entity name
				$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
				
				// Validate the entity's existence
				if (!$this->getEntityStore()->exists($fullEntityName)) {
					return $result;
				}
				
				// Fetch the entity's metadata
				$metadata = $this->getEntityStore()->getMetadata($fullEntityName);
				
				// Map the PHP field name to its database column name, then look up the column definition
				$referencedDbColumn = $metadata->columnMap[$referencedField] ?? null;
				
				if ($referencedDbColumn && isset($metadata->columnDefinitions[$referencedDbColumn])) {
					/** @var PhinxColumnType $colType */
					$colType = $metadata->columnDefinitions[$referencedDbColumn]['type'];
					$result['type'] = $colType;
					$result['unsigned'] = $metadata->columnDefinitions[$referencedDbColumn]['unsigned'] ?? true;
				}
			} catch (\Exception $e) {
				$this->output->writeLn("\nCouldn't determine FK type, defaulting to integer");
			}
			
			return $result;
		}
		
		/**
		 * Validates collected property definitions before passing them to EntityModifier.
		 * This is the single validation boundary for all property shapes produced by this command.
		 * @param array<int, PropertyDefinition> $properties
		 * @throws \InvalidArgumentException When a property definition is structurally invalid
		 */
		private function validateProperties(array $properties): void {
			foreach ($properties as $property) {
				// RelationProperty requires targetEntity; guard against any caller that bypasses
				// the typed builders and passes a raw array with relationshipType but no targetEntity.
				if (isset($property['relationshipType'])) {
					if (empty($property['targetEntity'])) {
						throw new \InvalidArgumentException(
							"Property '{$property['name']}' with relationshipType requires targetEntity"
						);
					}
				}
				
				// EnumProperty requires enumType; this is enforced by the type system (enumType is
				// non-optional on EnumProperty) but kept here as a runtime safety net for callers
				// that construct property arrays outside the typed builders.
				if ($property['type'] === 'enum' && !isset($property['enumType'])) {
					throw new \InvalidArgumentException(
						"Enum property '{$property['name']}' requires enumType"
					);
				}
			}
		}
	}