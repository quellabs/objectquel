<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntitySchemaAnalyzer;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhinxMigrationBuilder;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * MakeMigration - CLI command for generating database migrations
	 *
	 * This command uses the EntitySchemaAnalyzer to detect differences between entity definitions
	 * and the database schema, then uses PhinxMigrationBuilder to create migration files that
	 * synchronize the database with entity changes.
	 */
	class MakeMigrationsCommand extends CommandBase {
		private ?DatabaseAdapter $connection = null;
		private ?AnnotationReader $annotationReader = null;
		private ?EntityStore $entityStore = null;
		private array $entityPaths;
		private string $migrationsPath;
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
			$this->entityPaths = $this->configuration->getEntityPaths();
			$this->migrationsPath = $this->configuration->getMigrationsPath();
		}
		
		/**
		 * Execute the database migration generation command
		 * @param ConfigurationManager $config Parameters passed to the command
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("Generating database migrations based on entity changes...");
			
			// Step 1: Fetch the entity map from the Entity Store
			$entityMap = $this->getEntityStore()->getEntityMap();
			
			if (empty($entityMap)) {
				$this->output->writeLn("No entity classes found.");
				return 1;
			}
			
			// Step 2: Analyze changes between entities and database
			$entitySchemaAnalyzer = new EntitySchemaAnalyzer($this->getConnection(), $this->getEntityStore());
			$allChanges = $entitySchemaAnalyzer->analyzeEntityChanges($entityMap);
			
			// Step 3: Generate a migration file based on changes
			$migrationBuilder = new PhinxMigrationBuilder($this->migrationsPath);
			$result = $migrationBuilder->generateMigrationFile($allChanges);
			
			if (!$result['success']) {
				$this->output->writeLn($result['message']);
				return 1;
			}
			
			$this->output->writeLn($result['message'] . ": " . $result['path']);
			return 0;
		}
		
		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return "make:migrations";
		}
		
		/**
		 * Get a short description of what the command does
		 * @return string Command description
		 */
		public function getDescription(): string {
			return "Generate database migrations based on entity changes";
		}
		
		/**
		 * Get detailed help information for the command
		 * @return string Command help text
		 */
		public function getHelp(): string {
			return "Creates a new database migration file by comparing entity definitions with current database schema to synchronize changes.";
		}
		
		/**
		 * Returns the database connector using lazy initialization pattern
		 * @return DatabaseAdapter The database adapter instance
		 */
		private function getConnection(): DatabaseAdapter {
			// Check if connection has already been established
			if ($this->connection === null) {
				// Create a new database adapter with stored configuration
				// This only happens on first call (lazy initialization)
				$this->connection = new DatabaseAdapter($this->configuration);
			}
			
			// Return the existing or newly created connection
			return $this->connection;
		}
		
		/**
		 * Returns the AnnotationReader object
		 * @return AnnotationReader
		 */
		private function getAnnotationReader(): AnnotationReader {
			// Check if annotation reader is already initialized to avoid recreating it
			if ($this->annotationReader === null) {
				// Create a new configuration object for the annotation reader
				$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
				
				// Configure whether to use annotation caching based on the main configuration
				$annotationReaderConfiguration->setUseAnnotationCache($this->configuration->useMetadataCache());
				
				// Set the cache path for annotations from the main configuration
				$annotationReaderConfiguration->setAnnotationCachePath($this->configuration->getMetadataCachePath());
				
				// Initialize the annotation reader with the configured settings
				$this->annotationReader = new AnnotationReader($annotationReaderConfiguration);
			}
			
			// Return the initialized annotation reader instance
			return $this->annotationReader;
		}
		
		/**
		 * Returns the EntityStore object
		 * @return EntityStore
		 */
		private function getEntityStore(): EntityStore {
			// Check if the EntityStore instance has already been created (lazy loading)
			if ($this->entityStore === null) {
				$this->entityStore = new EntityStore($this->configuration);
			}
			
			// Return the EntityStore instance (either newly created or existing)
			return $this->entityStore;
		}
	}