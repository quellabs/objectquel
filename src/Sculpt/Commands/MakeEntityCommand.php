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
	 * MakeEntityCommand - CLI command for creating or updating entity classes
	 */
	class MakeEntityCommand extends CommandBase {
		
		private ?EntityModifier $entityModifier = null;
		private ?EntityStore $entityStore = null;
		private Configuration $configuration;
		
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		public function getSignature(): string {
			return "make:entity";
		}
		
		public function getDescription(): string {
			return "Create or update an entity class with properties and relationships";
		}
		
		public function getHelp(): string {
			return "Creates or updates an entity class with standard properties and ORM relationship mappings.\n" .
				"Supported relationship types: OneToOne, OneToMany, ManyToOne.";
		}
		
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
		
		private function getEntityStore(): EntityStore {
			if ($this->entityStore === null) {
				$this->entityStore = new EntityStore($this->configuration);
			}
			return $this->entityStore;
		}
		
		private function getEntityModifier(): EntityModifier {
			if ($this->entityModifier === null) {
				$this->entityModifier = new EntityModifier($this->configuration);
			}
			return $this->entityModifier;
		}
		
		private function displayEntityOperationMessage(string $entityName): void {
			$entityNamePlus = $entityName . "Entity";
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if (!$this->getEntityModifier()->entityExists($entityNamePlus)) {
				$this->output->writeLn("\nCreating new entity: {$entityPath}/{$entityNamePlus}.php\n");
			} else {
				$this->output->writeLn("\nUpdating existing entity: {$entityPath}/{$entityNamePlus}.php\n");
			}
		}
		
		private function getAvailableEntities(): array {
			$entityPath = realpath($this->configuration->getEntityPath());
			$availableEntities = [];
			
			if (!is_dir($entityPath)) {
				return $availableEntities;
			}
			
			$files = scandir($entityPath);
			
			foreach ($files as $file) {
				if (is_dir($entityPath . '/' . $file) || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
					continue;
				}
				
				$entityFileName = pathinfo($file, PATHINFO_FILENAME);
				
				if (str_ends_with($entityFileName, 'Entity')) {
					$availableEntities[] = substr($entityFileName, 0, -6);
				}
			}
			
			return $availableEntities;
		}
		
		private function collectProperties(array $availableEntities, string $entityName): array {
			$properties = [];
			
			while (true) {
				$propertyName = $this->input->ask("New property name (press enter to stop adding fields)");
				
				if (empty($propertyName)) {
					break;
				}
				
				$propertyType = $this->input->choice("\nField type", [
					'tinyinteger', 'smallinteger','integer', 'biginteger', 'string', 'char', 'text', 'float',
					'decimal', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'enum', 'relationship',
				]);
				
				if ($propertyType === 'relationship') {
					$relationshipProperties = $this->collectRelationshipProperties(
						$availableEntities,
						$entityName
					);
					$properties = array_merge($properties, $relationshipProperties);
				} else {
					$properties[] = $this->collectStandardProperties($propertyName, $propertyType);
				}
			}
			
			return $properties;
		}
		
		private function collectRelationshipProperties(array $availableEntities, string $entityName): array {
			$properties = [];
			
			$relationshipType = $this->input->choice("\nRelationship type", [
				'OneToOne', 'OneToMany', 'ManyToOne'
			]);
			
			$targetInfo = $this->getTargetEntityAndReferenceField($availableEntities);
			$targetEntity = $targetInfo['targetEntity'];
			$referencedField = $targetInfo['referencedField'];
			$foreignColumn = $targetInfo['foreignColumn'];
			
			// Auto-generate property name based on convention
			$propertyName = ($relationshipType === 'OneToMany')
				? StringInflector::pluralize(lcfirst($targetEntity))
				: lcfirst($targetEntity);
			
			// Determine FK details for owning side
			$relationColumn = null;
			$fkColumnType = 'integer';
			$fkUnsigned = true;
			
			if ($relationshipType === 'ManyToOne' || $relationshipType === 'OneToOne') {
				$relationColumn = $propertyName . "Id";
				$fkInfo = $this->determineForeignKeyType($targetEntity, $referencedField);
				$fkColumnType = $fkInfo['type'];
				$fkUnsigned = $fkInfo['unsigned'];
			}
			
			// Get mapping configuration
			$mappingConfig = $this->collectRelationshipMapping(
				$relationshipType,
				$entityName,
				$propertyName,
				$targetEntity
			);
			
			$propertyPhpType = ($relationshipType === 'OneToMany')
				? "CollectionInterface"
				: $targetEntity . "Entity";
			
			$relationshipNullable = $this->input->confirm(
				"\nAllow this relationship to be null?",
				$relationshipType === 'ManyToOne'
			);
			
			// Add relationship property
			$properties[] = [
				"name"             => $propertyName,
				"type"             => $propertyPhpType,
				"relationshipType" => $relationshipType,
				"targetEntity"     => $targetEntity,
				"mappedBy"         => $mappingConfig['mappedBy'],
				"inversedBy"       => $mappingConfig['inversedBy'],
				"relationColumn"   => $relationColumn,
				"foreignColumn"    => $foreignColumn,
				"nullable"         => $relationshipNullable,
				"readonly"         => false
			];
			
			// Add FK column for owning side
			if (
				($relationshipType === 'ManyToOne' || ($relationshipType === 'OneToOne' && $mappingConfig['mappedBy'] === null)) &&
				$relationColumn !== null
			) {
				$properties[] = [
					"name"     => $relationColumn,
					"type"     => $fkColumnType,
					"unsigned" => $fkUnsigned,
					"nullable" => $relationshipNullable,
					"readonly" => true
				];
			}
			
			// Create owning side in target entity if requested
			if ($mappingConfig['createOwningSide'] ?? false) {
				$this->createOwningSideInTargetEntity(
					$targetEntity,
					$entityName,
					$mappingConfig['owningSidePropertyName'],
					$relationshipType
				);
			}
			
			return $properties;
		}
		
		private function collectRelationshipMapping(string $relationshipType, string $entityName, string $propertyName, string $targetEntity): array {
			if ($relationshipType === 'OneToMany') {
				return $this->handleOneToManyMapping($targetEntity, $entityName);
			}
			
			if ($relationshipType === 'OneToOne' && !$this->input->confirm("\nIs this the owning side?", true)) {
				return $this->handleInverseOneToOneMapping($targetEntity, $entityName);
			}
			
			// Owning side (ManyToOne or OneToOne)
			$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
			
			if (!$bidirectional) {
				return ['mappedBy' => null, 'inversedBy' => null];
			}
			
			$inversedBy = $relationshipType === 'ManyToOne'
				? StringInflector::pluralize(lcfirst($entityName))
				: lcfirst($entityName);
			
			// If ManyToOne bidirectional, offer to create the inverse OneToMany collection
			if ($relationshipType === 'ManyToOne') {
				$createInverse = $this->input->confirm(
					"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
					true
				);
				
				if ($createInverse) {
					$this->createInverseSideInTargetEntity($targetEntity, $entityName, $inversedBy);
				}
			}
			
			return [
				'mappedBy' => null,
				'inversedBy' => $inversedBy
			];
		}
		
		private function handleOneToManyMapping(string $targetEntity, string $currentEntity): array {
			$mappedBy = $this->findOwningSideProperty($targetEntity, $currentEntity);
			
			if ($mappedBy !== null) {
				$this->output->writeLn("\nFound existing property '{$mappedBy}' in {$targetEntity}Entity");
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			$createOwningSide = $this->input->confirm(
				"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
				true
			);
			
			if (!$createOwningSide) {
				$mappedBy = $this->input->ask(
					"\nProperty name in {$targetEntity}Entity (you'll need to create it manually)",
					lcfirst($currentEntity)
				);
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			$owningSidePropertyName = $this->input->ask(
				"\nNew property name inside {$targetEntity}Entity",
				lcfirst($currentEntity)
			);
			
			return [
				'mappedBy' => $owningSidePropertyName,
				'inversedBy' => null,
				'createOwningSide' => true,
				'owningSidePropertyName' => $owningSidePropertyName
			];
		}
		
		private function handleInverseOneToOneMapping(string $targetEntity, string $currentEntity): array {
			$mappedBy = $this->findOwningSideProperty($targetEntity, $currentEntity);
			
			if ($mappedBy !== null) {
				$this->output->writeLn("\nFound existing property '{$mappedBy}' in {$targetEntity}Entity");
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			$createOwningSide = $this->input->confirm(
				"\nDo you want to add a new property to {$targetEntity}Entity to map the inverse of the relationship?",
				true
			);
			
			if (!$createOwningSide) {
				$mappedBy = $this->input->ask(
					"\nProperty name in {$targetEntity}Entity (you'll need to create it manually)",
					lcfirst($currentEntity)
				);
				return ['mappedBy' => $mappedBy, 'inversedBy' => null];
			}
			
			$owningSidePropertyName = $this->input->ask(
				"\nNew property name inside {$targetEntity}Entity",
				lcfirst($currentEntity)
			);
			
			return [
				'mappedBy' => $owningSidePropertyName,
				'inversedBy' => null,
				'createOwningSide' => true,
				'owningSidePropertyName' => $owningSidePropertyName
			];
		}
		
		private function createOwningSideInTargetEntity(
			string $targetEntity,
			string $currentEntity,
			string $owningSidePropertyName,
			string $inverseRelationshipType
		): void {
			if (!$this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				$this->output->warning(
					"\nTarget entity '{$targetEntity}Entity' doesn't exist yet. " .
					"Create it first, then add the relationship."
				);
				return;
			}
			
			$owningSideRelationType = ($inverseRelationshipType === 'OneToMany') ? 'ManyToOne' : 'OneToOne';
			
			$primaryKeys = $this->getEntityPrimaryKeys($currentEntity);
			$referencedField = $primaryKeys[0] ?? 'id';
			
			$fullCurrentEntity = $this->configuration->getEntityNameSpace() . '\\' . $currentEntity . 'Entity';
			$columnMap = $this->getEntityStore()->getColumnMap($fullCurrentEntity);
			$foreignColumn = $columnMap[$referencedField] ?? $referencedField;
			
			$fkInfo = $this->determineForeignKeyType($currentEntity, $referencedField);
			$relationColumn = $owningSidePropertyName . "Id";
			
			$inversedBy = ($inverseRelationshipType === 'OneToMany')
				? StringInflector::pluralize(lcfirst($currentEntity))
				: lcfirst($currentEntity);
			
			$owningSideProperty = [
				"name"             => $owningSidePropertyName,
				"type"             => $currentEntity . "Entity",
				"relationshipType" => $owningSideRelationType,
				"targetEntity"     => $currentEntity,
				"mappedBy"         => null,
				"inversedBy"       => $inversedBy,
				"relationColumn"   => $relationColumn,
				"foreignColumn"    => $foreignColumn,
				"nullable"         => true,
				"readonly"         => false
			];
			
			$fkProperty = [
				"name"     => $relationColumn,
				"type"     => $fkInfo['type'],
				"unsigned" => $fkInfo['unsigned'],
				"nullable" => true,
				"readonly" => true
			];
			
			$this->getEntityModifier()->createOrUpdateEntity($targetEntity, [$owningSideProperty, $fkProperty]);
			$this->output->writeLn("\nAdded property '{$owningSidePropertyName}' to {$targetEntity}Entity");
		}
		
		private function createInverseSideInTargetEntity(string $targetEntity, string $currentEntity, string $inversePropertyName): void {
			if (!$this->getEntityModifier()->entityExists($targetEntity . "Entity")) {
				$this->output->warning(
					"\nTarget entity '{$targetEntity}Entity' doesn't exist yet. " .
					"Create it first, then add the relationship."
				);
				return;
			}
			
			$primaryKeys = $this->getEntityPrimaryKeys($currentEntity);
			$referencedField = $primaryKeys[0] ?? 'id';
			
			$fullCurrentEntity = $this->configuration->getEntityNameSpace() . '\\' . $currentEntity . 'Entity';
			$columnMap = $this->getEntityStore()->getColumnMap($fullCurrentEntity);
			$foreignColumn = $columnMap[$referencedField] ?? $referencedField;
			
			$inverseProperty = [
				"name"             => $inversePropertyName,
				"type"             => "CollectionInterface",
				"relationshipType" => "OneToMany",
				"targetEntity"     => $currentEntity,
				"mappedBy"         => lcfirst($targetEntity),
				"inversedBy"       => null,
				"relationColumn"   => null,
				"foreignColumn"    => $foreignColumn,
				"nullable"         => false,
				"readonly"         => false
			];
			
			$this->getEntityModifier()->createOrUpdateEntity($targetEntity, [$inverseProperty]);
			$this->output->writeLn("\nAdded collection property '{$inversePropertyName}' to {$targetEntity}Entity");
		}
		
		private function findOwningSideProperty(string $targetEntity, string $currentEntity): ?string {
			$fullTargetEntity = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
			
			if (!$this->getEntityStore()->exists($fullTargetEntity)) {
				return null;
			}
			
			$reflectionClass = new \ReflectionClass($fullTargetEntity);
			$properties = $reflectionClass->getProperties();
			
			$matchingProperties = [];
			$currentEntityFullName = $this->configuration->getEntityNameSpace() . '\\' . $currentEntity . 'Entity';
			
			foreach ($properties as $property) {
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
				return $this->input->choice(
					"\nMultiple properties found. Select the owning side property:",
					$matchingProperties
				);
			}
			
			return null;
		}
		
		private function collectStandardProperties(string $propertyName, string $propertyType): array {
			$property = [
				"name"     => $propertyName,
				"type"     => $propertyType,
				"readonly" => false
			];
			
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
			
			$property['nullable'] = $this->input->confirm(
				"\nAllow this field to be empty/null in the database?",
				false
			);
			
			return $property;
		}
		
		private function collectDecimalConfiguration(): array {
			$precision = null;
			$scale = null;
			
			while ($precision === null || $precision <= 0) {
				$precision = (int) $this->input->ask("\nPrecision (total digits, e.g. 10)?", 10);
				
				if ($precision <= 0) {
					$this->output->warning("Precision must be greater than 0.");
				}
			}
			
			while ($scale === null || $scale < 0 || $scale > $precision) {
				$scale = (int) $this->input->ask("\nScale (decimal digits, e.g. 2)?", 2);
				
				if ($scale < 0) {
					$this->output->warning("Scale cannot be negative.");
				} elseif ($scale > $precision) {
					$this->output->warning("Scale cannot be greater than precision ($precision).");
				}
			}
			
			return ['precision' => $precision, 'scale' => $scale];
		}
		
		private function collectEnumType(): string {
			$enumType = null;
			
			while ($enumType === null) {
				$enumType = $this->input->ask("Enter fully qualified enum class name (e.g. App\Enum\OrderStatus)");
				
				if (enum_exists($enumType)) {
					break;
				}
				
				$this->output->error("Invalid enum class name");
				$enumType = null;
			}
			
			return $enumType;
		}
		
		private function getTargetEntityAndReferenceField(array $availableEntities): array {
			$targetEntity = $this->selectTargetEntity($availableEntities);
			
			if (!in_array($targetEntity, $availableEntities)) {
				return [
					'targetEntity' => $targetEntity,
					'referencedField' => 'id',
					'foreignColumn' => 'id'
				];
			}
			
			$refInfo = $this->getTargetEntityReferenceField($targetEntity);
			
			return [
				'targetEntity' => $targetEntity,
				'referencedField' => $refInfo['field'],
				'foreignColumn' => $refInfo['column']
			];
		}
		
		private function selectTargetEntity(array $availableEntities): string {
			if (empty($availableEntities)) {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			}
			
			$targetEntityOptions = array_merge($availableEntities, ['[Enter manually]']);
			$targetEntityChoice = $this->input->choice("\nSelect target entity", $targetEntityOptions);
			
			if ($targetEntityChoice === '[Enter manually]') {
				return $this->input->ask("\nTarget entity name (without 'Entity' suffix)");
			}
			
			return $targetEntityChoice;
		}
		
		private function getTargetEntityReferenceField(string $targetEntity): array {
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
			
			$primaryKeys = $this->getEntityPrimaryKeys($targetEntity);
			
			if (empty($primaryKeys)) {
				return ['field' => 'id', 'column' => 'id'];
			}
			
			$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
			$primaryKeyField = $primaryKeys[0];
			
			return [
				'field' => $primaryKeyField,
				'column' => $columnMap[$primaryKeyField] ?? $primaryKeyField
			];
		}
		
		public function getEntityPrimaryKeys(string $entityName): array {
			$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $entityName . 'Entity';
			
			if ($this->getEntityStore()->exists($fullEntityName)) {
				return $this->getEntityStore()->getIdentifierKeys($fullEntityName);
			}
			
			return ['id'];
		}
		
		private function determineForeignKeyType(string $targetEntity, string $referencedField): array {
			$result = ['type' => 'integer', 'unsigned' => true];
			
			try {
				$fullEntityName = $this->configuration->getEntityNameSpace() . '\\' . $targetEntity . 'Entity';
				
				if ($this->getEntityStore()->exists($fullEntityName)) {
					$columnDefinitions = $this->getEntityStore()->extractEntityColumnDefinitions($fullEntityName);
					$columnMap = $this->getEntityStore()->getColumnMap($fullEntityName);
					$referencedDbColumn = $columnMap[$referencedField] ?? null;
					
					if ($referencedDbColumn && isset($columnDefinitions[$referencedDbColumn])) {
						$result['type'] = $columnDefinitions[$referencedDbColumn]['type'];
						$result['unsigned'] = $columnDefinitions[$referencedDbColumn]['unsigned'] ?? true;
					}
				}
			} catch (\Exception $e) {
				$this->output->writeLn("\nCouldn't determine FK type, defaulting to integer");
			}
			
			return $result;
		}
	}