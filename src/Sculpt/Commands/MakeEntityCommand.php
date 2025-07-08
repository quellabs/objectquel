<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	/**
	 * Import required classes for entity management and console interaction
	 */
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
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
		private ?EntityStore $entityStore;
		
		/**
		 * @var Configuration
		 */
		private Configuration $configuration;
		
		/**
		 * MakeEntityCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ProviderInterface|null $provider
		 * @throws OrmException
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ProviderInterface $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
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
		 * Execute the command
		 * @param ConfigurationManager $config Parameters passed to the command
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Ask for entity name
			$entityName = $this->input->ask("Class name of the entity to create or update (e.g. AgreeableElephant)");
			
			// If none given, do nothing and exit gracefully
			if (empty($entityName)) {
				return 0;
			}
			
			// Show the appropriate message to user based on whether the entity exists
			$entityNamePlus = $entityName . "Entity";
			$entityPath = realpath($this->configuration->getEntityPath());
			
			if (!$this->getEntityModifier()->entityExists($entityNamePlus)) {
				$this->output->writeLn("\nCreating new entity: {$entityPath}/{$entityNamePlus}.php\n");
			} else {
				$this->output->writeLn("\nUpdating existing entity: {$entityPath}/{$entityNamePlus}.php\n");
			}
			
			// Get list of available entities for relationships
			$availableEntities = [];
			
			if (is_dir($entityPath)) {
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
			}
			
			// Initialize an empty properties array
			$properties = [];
			
			// Loop to collect multiple property definitions
			while (true) {
				// Get property name or break the loop if empty
				$propertyName = $this->input->ask("New property name (press <return> to stop adding fields)");
				
				if (empty($propertyName)) {
					break;
				}
				
				// Prompt user to select property data type from available options
				$propertyType = $this->input->choice("\nField type", [
					'tinyinteger', 'smallinteger','integer', 'biginteger', 'string', 'char', 'text', 'float',
					'decimal', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'relationship'
				]);
				
				// If the type is relationship, collect relationship details
				if ($propertyType === 'relationship') {
					// Collect relationship information
					$relationshipType = $this->input->choice("\nRelationship type", [
						'OneToOne', 'OneToMany', 'ManyToOne'
					]);
					
					// Get target entity and reference field information
					$targetInfo = $this->getTargetEntityAndReferenceField($availableEntities);
					$targetEntity = $targetInfo['targetEntity'];
					$referencedField = $targetInfo['referencedField'];
					$referencedColumnName = $targetInfo['referencedColumnName'];
					
					// For OneToMany and ManyToOne, ask for mappedBy/inversedBy
					$mappedBy = null;
					$inversedBy = null;
					$joinColumnName = null;
					
					if ($relationshipType === 'ManyToOne' || $relationshipType === 'OneToOne') {
						// For ManyToOne and owning side of OneToOne, generate join column name
						$joinColumnName = lcfirst($targetEntity) . ucfirst($referencedField);
						$this->output->writeLn("\nJoin column name (for storage in database): " . $joinColumnName);
					}
					
					// Handle relationship type-specific configuration
					switch ($relationshipType) {
						case 'OneToMany':
							// For a OneToMany relationship, the mappedBy field should be the same as
							// the primary key field selected for the reference
							
							// We already asked the user to select a primary key field to reference
							// So we'll use the same field for mappedBy
							$mappedBy = $referencedField;
							
							$this->output->writeLn("\nUsing '{$mappedBy}' as the mappedBy field in the related entity");
							break;
						
						case 'ManyToOne':
							// Ask if this is a bidirectional relationship
							$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
							
							if ($bidirectional) {
								// Suggest a name for the collection in the target entity
								$suggestedCollectionName = lcfirst($entityName) . 's';
								$inversedBy = $this->input->ask("\nInversedBy field name in the related entity", $suggestedCollectionName);
							}
							
							break;
						
						case 'OneToOne':
							// For OneToOne, ask if this is the owning side
							$isOwningSide = $this->input->confirm("\nIs this the owning side of the relationship?", true);
							
							if (!$isOwningSide) {
								// If not the owning side, ask for mappedBy
								$suggestedFieldName = lcfirst($entityName);
								$mappedBy = $this->input->ask("\nMappedBy field name in the related entity", $suggestedFieldName);
							} else {
								// If owning side, ask if it's bidirectional
								$bidirectional = $this->input->confirm("\nIs this a bidirectional relationship?", false);
								
								if ($bidirectional) {
									// Suggest a name for the field in the target entity
									$suggestedFieldName = lcfirst($entityName);
									$inversedBy = $this->input->ask("\nInversedBy field name in the related entity", $suggestedFieldName);
								}
							}
							
							break;
					}
					
					// For OneToMany, the property will be a collection
					if ($relationshipType === 'OneToMany') {
						$propertyPhpType = "CollectionInterface";
					} else {
						$propertyPhpType = $targetEntity . "Entity";
					}
					
					// Add the relationship property
					$properties[] = [
						"name"                 => $propertyName,
						"type"                 => $propertyPhpType,
						"relationshipType"     => $relationshipType,
						"targetEntity"         => $targetEntity,
						"mappedBy"             => $mappedBy,
						"inversedBy"           => $inversedBy,
						"joinColumnName"       => $joinColumnName,
						"referencedColumnName" => $referencedColumnName,
						"nullable"             => $this->input->confirm("\nAllow this relationship to be null?", $relationshipType === 'ManyToOne'),
					];
					
					// Continue to next property
					continue;
				}
				
				// For string type, ask for length; otherwise set to null
				if ($propertyType == 'string') {
					$propertyLimit = $this->input->ask("\nCharacter limit for this string field", "255");
				} else {
					$propertyLimit = null;
				}
				
				// For integer types, ask if unsigned; otherwise set to null
				if (in_array($propertyType, ['tinyinteger', 'smallinteger', 'integer', 'biginteger'])) {
					$unsigned = $this->input->confirm("\nShould this number field store positive values only (unsigned)?", false);
				} else {
					$unsigned = null;
				}
				
				// For decimal types, ask for precision and scale
				$precision = null;
				$scale = null;
				
				if (in_array($propertyType, ['decimal'])) {
					// Ask for precision with validation
					$precision = null;
					
					while ($precision === null || $precision <= 0) {
						$precision = (int) $this->input->ask("\nPrecision (total digits, e.g. 10)?", 10);
						
						if ($precision <= 0) {
							$this->output->warning("Precision must be greater than 0.");
						}
					}
					
					// Ask for scale with validation against precision
					$scale = null;
					
					while ($scale === null || $scale < 0 || $scale > $precision) {
						$scale = (int) $this->input->ask("\nScale (decimal digits, e.g. 2)?", 2);
						
						if ($scale < 0) {
							$this->output->warning("Scale cannot be negative.");
						} elseif ($scale > $precision) {
							$this->output->warning("Scale cannot be greater than precision ($precision).");
						}
					}
				}
				
				// Ask if property can be nullable in the database
				$propertyNullable = $this->input->confirm("\nAllow this field to be empty/null in the database?", false);
				
				// Add collected property info to the property array
				$properties[] = [
					"name"      => $propertyName,
					"type"      => $propertyType,
					"limit"     => $propertyLimit,
					'unsigned'  => $unsigned,
					"nullable"  => $propertyNullable,
					'precision' => $precision,
					'scale'     => $scale,
				];
			}
			
			// If properties were defined, create or update the entity
			if (!empty($properties)) {
				$this->getEntityModifier()->createOrUpdateEntity($entityName, $properties);
				$this->output->writeLn("Entity details written");
			}
			
			// Return success code
			return 0;
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
		 * Gets the target entity and reference field information for a relationship
		 * @param array $availableEntities List of available entities
		 * @return array Associative array with targetEntity, referencedField, and referencedColumnName
		 */
		private function getTargetEntityAndReferenceField(array $availableEntities): array {
			// Set default values
			$result = [
				'targetEntity'         => '',
				'referencedField'      => 'id',
				'referencedColumnName' => 'id'
			];
			
			// Get the target entity (either from selection or manual entry)
			$result['targetEntity'] = $this->selectTargetEntity($availableEntities);
			
			// If we have a valid existing entity, get its primary key
			if (in_array($result['targetEntity'], $availableEntities)) {
				$referenceInfo = $this->getTargetEntityReferenceField($result['targetEntity']);
				$result['referencedField'] = $referenceInfo['field'];
				$result['referencedColumnName'] = $referenceInfo['column'];
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