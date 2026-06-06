<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\StringInflector;
	
	/**
	 * MakeEntityCommand - Create or update entity classes interactively
	 *
	 * Guides the user through defining entity properties and ORM relationships
	 * via interactive prompts, then generates or updates the corresponding
	 * entity class file on disk.
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
	class MakeEntityCommand extends MakeCommandBase {
		
		/** @var EntityModifier|null Lazy-loaded entity modifier for creating/updating entity files */
		private ?EntityModifier $entityModifier = null;
		
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
			return <<<HELP
DESCRIPTION:
    Interactively create or update an entity class with properties and ORM
    relationship mappings. If the entity already exists, new properties are
    appended without touching existing ones.

USAGE:
    php sculpt make:entity

ARGUMENTS:
    None — all input is collected via interactive prompts

RELATIONSHIP TYPES:
    OneToOne      Owning side of a one-to-one relationship (holds the FK)
    ManyToOne     Owning side of a many-to-one relationship (holds the FK)
    InverseOf     Hydration hint telling the system which property on the related entity to populate

FIELD TYPES:
    string, integer, biginteger, smallinteger, tinyinteger,
    boolean, decimal, float, char, text, date, datetime, time,
    timestamp, enum, relationship

NOTES:
    - The "id" property is reserved and generated automatically
    - Foreign key columns are added automatically for owning-side relationships
    - Enter ? at the field type prompt to see all available types
HELP;
		}
		
		/**
		 * Execute the command - prompts for entity name and properties, then generates/updates the entity class.
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Output banner
			$this->output->writeLn("");
			$this->output->writeLn(" ██████╗ ██╗   ██╗███████╗██╗");
			$this->output->writeLn("██╔═══██╗██║   ██║██╔════╝██║");
			$this->output->writeLn("██║   ██║██║   ██║█████╗  ██║");
			$this->output->writeLn("██║▄▄ ██║██║   ██║██╔══╝  ██║");
			$this->output->writeLn("╚██████╔╝╚██████╔╝███████╗███████╗");
			$this->output->writeLn(" ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝");
			$this->output->writeLn("");
			$this->output->writeLn("Creating entity...");
			$this->output->writeLn("");
			
			// Ask for the entity name
			$entityName = $this->collectIdentifier("Class name of the entity to create or update (e.g. AgreeableElephant)");
			
			// Show message that we are making a new or modifying an existing entity
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
				// Collect a valid, unique property name; null means the user pressed Enter to stop.
				$propertyName = $this->collectPropertyName($properties);
				
				if ($propertyName === null) {
					break;
				}
				
				$propertyType = $this->collectFieldType();
				
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
				"relation"         => $relation,
				"referencedColumn" => $referencedColumn,
				"localColumn"      => $relationColumn,
				"nullable"         => $nullable,
				"readonly"         => false,
				"collection"       => $phpType === 'CollectionInterface',
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
		 * @param string $propertyName Property name on the current entity
		 * @param string[] $availableEntities List of available entity names
		 * @param string $entityName Name of the current entity being created
		 * @return array<int, PropertyDefinition> Array of property definitions for the relationship
		 * @throws EntityResolutionException
		 */
		private function collectRelationshipProperties(string $propertyName, array $availableEntities, string $entityName): array {
			/** @var OrmRelationshipType $relationshipType */
			$relationshipType = $this->input->choice("\nRelationship type", ['OneToOne', 'ManyToOne', 'InverseOf']);
			
			$targetInfo = $this->getTargetEntityInfo($availableEntities);
			
			// Get relationship mapping configuration — null means abort this property
			$mappingConfig = $this->collectRelationshipMapping($relationshipType, $entityName, $targetInfo['targetEntity']);
			
			if ($mappingConfig === null) {
				return [];
			}
			
			$nullable = $this->input->confirm("\nAllow this relationship to be null?", $relationshipType === 'ManyToOne');
			
			// Build the owning side: relationship property + FK column (for ManyToOne and OneToOne)
			$properties = $this->buildOwningSideProperties(
				$propertyName, $relationshipType, $targetInfo, $mappingConfig, $nullable
			);
			
			// Optionally mirror the relationship on the target entity
			if (($mappingConfig['createInTarget'] ?? false) === true) {
				$this->createRelationshipInTargetEntity(
					$targetInfo['targetEntity'],
					$entityName,
					$mappingConfig['targetPropertyName'],
					$mappingConfig['targetRelationType'],
					$propertyName,
					$relationshipType
				);
			}
			
			return $properties;
		}
		
		/**
		 * Collect relationship mapping configuration, including whether to create an @InverseOf on the target entity.
		 * Returns via/inversedBy values and whether to create property in target entity.
		 * The return type is a union: when createInTarget is absent/false the optional keys are
		 * absent; when createInTarget is true they are always present, so PHPStan won't flag
		 * offsetAccess.notFound at the call site.
		 * @param string $relationshipType Type of relationship (OneToOne, InverseOf, ManyToOne)
		 * @param string $entityName Name of the current entity
		 * @param string $targetEntity Name of the target entity
		 * @return RelationshipMappingConfig
		 */
		private function collectRelationshipMapping(string $relationshipType, string $entityName, string $targetEntity): ?array {
			if ($relationshipType === 'InverseOf') {
				return $this->handleInverseSideMapping($targetEntity, $entityName, 'ManyToOne');
			} else {
				return $this->handleOwningSideMapping($relationshipType, $entityName, $targetEntity);
			}
		}
		
		/**
		 * Handle mapping for the inverse side of a relationship.
		 * Returns null when the operation should be aborted — the caller must skip
		 * adding properties and return to the property name loop.
		 * @param string $targetEntity Name of the target entity (contains the owning side)
		 * @param string $currentEntity Name of the current entity (inverse side)
		 * @param string $targetRelationType Relationship type on the target side (ManyToOne or OneToOne)
		 * @phpstan-param OrmRelationshipType $targetRelationType Relationship type on the target side (ManyToOne or OneToOne)
		 * @return RelationshipMappingConfig|null Null signals the caller to abort this property
		 */
		private function handleInverseSideMapping(string $targetEntity, string $currentEntity, string $targetRelationType): ?array {
			// Try to auto-detect an existing owning side property via reflection
			$detected = $this->findRelationshipProperty($targetEntity, $currentEntity);
			
			if ($detected !== null) {
				$this->output->writeLn("\nFound existing property '{$detected}' in {$targetEntity}Entity");
			} elseif ($this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				// Target entity exists but has no property pointing back — proceeding without
				// creating one would produce a broken @InverseOf annotation at runtime.
				$this->output->warning(
					"\nNo ManyToOne or OneToOne property pointing to {$currentEntity} was found in {$targetEntity}Entity.\n" .
					"Add one to {$targetEntity}Entity first, then come back to create the InverseOf."
				);
				
				return null;
			}
			
			// Always ask — relation= must be explicit and correct
			$relation = $this->input->ask(
				"\nProperty name on {$targetEntity}Entity that holds the FK (relation=)",
				$detected ?? lcfirst($currentEntity)
			);
			
			return ['relation' => $relation, 'referencedColumn' => null];
		}
		
		/**
		 * Handle mapping for the owning side of a relationship.
		 * @param string $relationshipType Type of relationship (ManyToOne or OneToOne)
		 * @param string $entityName Name of the current entity (owning side)
		 * @param string $targetEntity Name of the target entity
		 * @return RelationshipMappingConfig
		 */
		private function handleOwningSideMapping(string $relationshipType, string $entityName, string $targetEntity): array {
			$confirmMessage = ($relationshipType === 'ManyToOne')
				? "\nAdd an @InverseOf collection to {$targetEntity}Entity for reverse access?"
				: "\nAdd an @InverseOf back-reference to {$targetEntity}Entity for reverse access?";
			
			$bidirectional = $this->input->confirm($confirmMessage, false);
			
			if (!$bidirectional) {
				return ['relation' => null, 'referencedColumn' => null];
			}
			
			return $this->buildBidirectionalConfig($relationshipType, $entityName);
		}
		
		/**
		 * Create a relationship property in the target entity.
		 * @param string $targetEntity Name of the target entity
		 * @param string $currentEntity Name of the current entity
		 * @param string $propertyName Name of the property to create
		 * @param OrmRelationshipType $relationshipType Type of relationship
		 * @param string|null $referencedColumn Property name for the inverse side
		 * @param string|null $originatingRelationshipType The relationship type on the owning side (ManyToOne or OneToOne)
		 * @throws EntityResolutionException
		 */
		private function createRelationshipInTargetEntity(
			string $targetEntity,
			string $currentEntity,
			string $propertyName,
			string $relationshipType,
			?string $referencedColumn,
			?string $originatingRelationshipType = null
		): void {
			if (!$this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				$this->output->warning(
					"\nTarget entity '{$targetEntity}Entity' doesn't exist yet. " .
					"Create it first, then add the relationship."
				);
				return;
			}
			
			$properties = $this->buildTargetRelationshipProperties(
				$propertyName, $relationshipType, $currentEntity, $referencedColumn, $originatingRelationshipType
			);
			
			$this->validateProperties($properties);
			
			if ($this->getEntityModifier()->createOrUpdateEntity($targetEntity, $properties)) {
				$this->output->writeLn("\nAdded property '{$propertyName}' to {$targetEntity}Entity");
			} else {
				$this->output->warning("\nFailed to update {$targetEntity}Entity. Check file permissions and try again.");
			}
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
			
			if (!class_exists($fullTargetEntity) || !$this->getEntityStore()->exists($fullTargetEntity)) {
				return null;
			}
			
			$matchingProperties = $this->findMatchingReflectionProperties($fullTargetEntity, $currentEntity);
			
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
			
			// Collect type-specific attributes (length, unsigned, precision/scale)
			/** @var PhinxColumnType $propertyType */
			/** @var BaseProperty $property */
			$property = array_merge($property, $this->collectTypeSpecificAttributes($propertyType));
			
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
				$enumType = $this->input->ask("Enter fully qualified enum class name (e.g. App\\Enum\\OrderStatus)");
				
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
			// No entities on disk yet — skip the choice menu and go straight to manual entry
			if (empty($availableEntities)) {
				return $this->collectIdentifier("\nTarget entity name (without 'Entity' suffix)");
			}
			
			$options = array_merge($availableEntities, ['[Enter manually]']);
			$choice = $this->input->choice("\nSelect target entity", $options);
			
			if ($choice === '[Enter manually]') {
				return $this->collectIdentifier("\nTarget entity name (without 'Entity' suffix)");
			}
			
			return $choice;
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
				$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
				
				if (!$this->getEntityStore()->exists($fullEntityName)) {
					return $result;
				}
				
				$metadata = $this->getEntityStore()->getMetadata($fullEntityName);
				
				// Map the PHP field name to its database column name, then look up the column definition
				$referencedDbColumn = $metadata->columnMap[$referencedField] ?? null;
				
				if ($referencedDbColumn && isset($metadata->columnDefinitions[$referencedDbColumn])) {
					/** @var PhinxColumnType $colType */
					$colType = $metadata->columnDefinitions[$referencedDbColumn]['type'];
					$result['type']     = $colType;
					$result['unsigned'] = $metadata->columnDefinitions[$referencedDbColumn]['unsigned'] ?? true;
				}
			} catch (\Exception $e) {
				// Metadata may not be available for entities not yet loaded by the autoloader;
				// fall back to the safe default rather than aborting the command
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
		
		// -------------------------------------------------------------------------
		// Private helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Build the relationship property and optional FK column for the owning side (ManyToOne, OneToOne).
		 * For InverseOf (inverse side), only the relationship property is returned — the FK lives
		 * in the other entity's table and must not be generated here.
		 * @param string $propertyName Property name on the current entity
		 * @param OrmRelationshipType $relationshipType ManyToOne, OneToOne, or InverseOf
		 * @param array{targetEntity: string, referencedField: string} $targetInfo Target entity and its PK field
		 * @param RelationshipMappingConfig $mappingConfig Collected mapping config from handleOwningSideMapping/handleInverseSideMapping
		 * @param bool $nullable Whether the relationship allows null
		 * @return array<int, PropertyDefinition>
		 */
		private function buildOwningSideProperties(
			string $propertyName,
			string $relationshipType,
			array $targetInfo,
			array $mappingConfig,
			bool $nullable
		): array {
			// InverseOf properties carry CollectionInterface; all owning side properties carry the entity type
			$phpType = ($relationshipType === 'InverseOf') ? "CollectionInterface" : $targetInfo['targetEntity'] . "Entity";
			
			// Owning side (ManyToOne, OneToOne) holds the FK column; inverse side does not
			$isOwningSide  = in_array($relationshipType, ['ManyToOne', 'OneToOne']);
			$relationColumn = $isOwningSide ? $propertyName . "Id" : null;
			
			$properties = [
				$this->buildRelationshipProperty(
					$propertyName,
					$phpType,
					$relationshipType,
					$targetInfo['targetEntity'],
					$mappingConfig['relation'],
					$mappingConfig['referencedColumn'],
					$relationColumn,
					$nullable
				)
			];
			
			// Add the FK column alongside the relationship property for owning sides
			if ($isOwningSide && $relationColumn !== null) {
				$fkInfo = $this->determineForeignKeyType($targetInfo['targetEntity'], $targetInfo['referencedField']);
				$properties[] = $this->buildForeignKeyProperty($relationColumn, $fkInfo['type'], $fkInfo['unsigned'], $nullable);
			}
			
			return $properties;
		}
		
		/**
		 * Build the relationship property (and FK column if applicable) to inject into the target entity.
		 * Called when the user opts in to bidirectional mapping while creating the owning side.
		 * @param string $propertyName Property name to create on the target entity
		 * @param OrmRelationshipType $relationshipType Relationship type for the new property
		 * @param string $currentEntity Name of the current (owning) entity
		 * @param string|null $referencedColumn Property name pointing back to the owning side
		 * @param string|null $originatingRelationshipType OneToOne or ManyToOne — determines whether InverseOf holds a scalar or collection
		 * @return array<int, PropertyDefinition>
		 */
		private function buildTargetRelationshipProperties(
			string $propertyName,
			string $relationshipType,
			string $currentEntity,
			?string $referencedColumn,
			?string $originatingRelationshipType
		): array {
			$isOwningSide = in_array($relationshipType, ['ManyToOne', 'OneToOne']);
			
			// InverseOf on a ManyToOne owning side → collection; on a OneToOne owning side → scalar
			if ($relationshipType === 'InverseOf') {
				$phpType = ($originatingRelationshipType === 'OneToOne') ? $currentEntity . "Entity" : "CollectionInterface";
			} else {
				$phpType = $currentEntity . "Entity";
			}
			
			// For InverseOf (inverse side), via should reference the property name
			// in the owning entity that we're creating the inverse for.
			// The $referencedColumn parameter contains the ManyToOne property name (e.g., "post")
			$relation = $isOwningSide ? null : $referencedColumn;
			
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
			
			// Add FK column when this side owns the relationship (holds the FK in its table).
			// If $currentEntity does not exist on disk yet, getEntityPrimaryKeys and
			// determineForeignKeyType both fall back to 'id' / integer unsigned safely.
			if ($isOwningSide) {
				$fkInfo = $this->determineForeignKeyType($currentEntity, $this->getEntityPrimaryKeys($currentEntity)[0]);
				$properties[] = $this->buildForeignKeyProperty($propertyName . "Id", $fkInfo['type'], $fkInfo['unsigned'], true);
			}
			
			return $properties;
		}
		
		/**
		 * Build the RelationshipMappingConfig for a bidirectional owning side.
		 * Extracted from handleOwningSideMapping to keep that method within one screen.
		 * @param string $relationshipType ManyToOne or OneToOne
		 * @param string $entityName The current (owning) entity name
		 * @return RelationshipMappingConfig
		 */
		private function buildBidirectionalConfig(string $relationshipType, string $entityName): array {
			// The property name on the inverse entity that will hold the back-reference:
			// ManyToOne → inverse holds a collection (pluralize); OneToOne → inverse holds a scalar
			$inversePropertyName = ($relationshipType === 'ManyToOne')
				? StringInflector::pluralize(lcfirst($entityName))
				: lcfirst($entityName);
			
			// The back-reference is always @InverseOf regardless of relationship type.
			// For ManyToOne this is a collection; for OneToOne it is a scalar. Either way
			// it carries no FK — the FK lives on the owning side only.
			return [
				'relation'           => null,
				'referencedColumn'   => null, // default to target PK
				'createInTarget'     => true,
				'targetPropertyName' => $inversePropertyName,
				'targetRelationType' => 'InverseOf',
				'targetInversedBy'   => null
			];
		}
		
		/**
		 * Use reflection to find all properties on $fullTargetEntity whose declared type
		 * matches $currentEntity (both fully qualified and short form are checked).
		 * @param class-string $fullTargetEntity Fully qualified class name of the target entity
		 * @param string $currentEntity Short entity name without namespace or "Entity" suffix
		 * @return string[] Property names whose type matches the current entity
		 */
		private function findMatchingReflectionProperties(string $fullTargetEntity, string $currentEntity): array {
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
			
			return $matchingProperties;
		}
		
		/**
		 * Prompts for a property name, re-prompting until the user enters a valid,
		 * non-duplicate name or presses Enter to stop.
		 * Returns null when the user presses Enter on an empty input.
		 * @param array<int, PropertyDefinition> $existingProperties Already-collected properties
		 * @return string|null Validated property name, or null to stop
		 */
		private function collectPropertyName(array $existingProperties): ?string {
			// Build a set of names already defined in this session for duplicate detection.
			// "id" is always reserved — ObjectQuel generates it automatically.
			$defined = array_column($existingProperties, 'name');
			
			while (true) {
				// Ask for property name
				$name = $this->input->ask("New property name (press enter to stop adding fields)");
				
				// Empty input signals the user is done adding properties
				if ($name === null || trim($name) === '') {
					return null;
				}
				
				// Reject numbers, php keywords, etc
				if (!$this->isValidPhpIdentifier($name)) {
					$this->output->warning("Invalid property name. Use letters, numbers and underscores only.");
					continue;
				}
				
				// "id" is generated automatically by ObjectQuel; reject it explicitly
				if (strtolower($name) === 'id') {
					$this->output->warning("The \"id\" property is reserved. ObjectQuel generates it automatically.");
					continue;
				}
				
				// Reject duplicate properties
				if (in_array($name, $defined, true)) {
					$this->output->warning("Property \"{$name}\" has already been defined.");
					continue;
				}
				
				return $name;
			}
		}
		
		/**
		 * Prompt the user to enter a field type by name.
		 * Displays a compact help screen when the user enters "?" and re-prompts until
		 * a valid type (or alias) is entered. Input is trimmed and lowercased before
		 * validation so "String", " int ", etc. are all accepted.
		 * @return string Canonical field type name
		 */
		private function collectFieldType(): string {
			$types = [
				'string', 'integer', 'boolean', 'date', 'enum', 'text', 'decimal',
				'datetime', 'time', 'relationship', 'char', 'float', 'biginteger',
				'timestamp', 'smallinteger', 'tinyinteger',
			];
			
			$aliases = [
				'int'      => 'integer',
				'bool'     => 'boolean',
				'relation' => 'relationship',
			];
			
			while (true) {
				$answer = trim((string)$this->input->ask("Field type (? for help)"));
				
				if ($answer === '?') {
					$colWidth = max(array_map('strlen', $types)) + 2;
					$this->output->writeLn("");
					$this->output->writeLn("Available field types:");
					
					foreach (array_chunk($types, 3) as $row) {
						$this->output->writeLn("  " . implode('', array_map(fn(string $t) => str_pad($t, $colWidth), $row)));
					}
					
					$this->output->writeLn("");
					continue;
				}
				
				$normalized = strtolower($answer);
				
				// Resolve alias first, then validate against the canonical type list
				$resolved = $aliases[$normalized] ?? $normalized;
				
				if (in_array($resolved, $types, true)) {
					return $resolved;
				}
				
				$this->output->warning("Unknown type \"{$answer}\". Enter ? to see available types.");
			}
		}
		
		/**
		 * Collect type-specific attributes for a standard property (length, unsigned, precision/scale).
		 * Returns a partial array to be merged into the base property definition.
		 * @param PhinxColumnType $propertyType The column type
		 * @return array<string, mixed> Partial property attributes
		 */
		private function collectTypeSpecificAttributes(string $propertyType): array {
			$attributes = [];
			
			// String length limit
			if ($propertyType === 'string') {
				$attributes['limit'] = (int)($this->input->ask("\nCharacter limit for this string field", "255") ?? "255");
			}
			
			// Integer unsigned flag
			if (in_array($propertyType, ['tinyinteger', 'smallinteger', 'integer', 'biginteger'])) {
				$attributes['unsigned'] = $this->input->confirm(
					"\nShould this number field store positive values only (unsigned)?",
					false
				);
			}
			
			// Decimal precision/scale
			if ($propertyType === 'decimal') {
				$decimalConfig = $this->collectDecimalConfiguration();
				$attributes['precision'] = $decimalConfig['precision'];
				$attributes['scale']     = $decimalConfig['scale'];
			}
			
			return $attributes;
		}
	}