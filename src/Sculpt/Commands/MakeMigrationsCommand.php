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
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
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
	 *
	 * @phpstan-import-type ColumnDefinition from EntitySchemaAnalyzer
	 */
	class MakeMigrationsCommand extends CommandBase {
		private ?EntityStore $entityStore = null;
		private string $migrationsPath;
		private Configuration $configuration;
		
		/** @var ServiceProvider|null */
		protected ?ProviderInterface $provider;
		
		/**
		 * MakeEntityCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ServiceProvider|null $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
			$this->migrationsPath = $this->configuration->getMigrationsPath();
		}
		
		/**
		 * Execute the database migration generation command
		 * @param ConfigurationManager $config Parameters passed to the command
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("Generating database migrations based on entity changes...");
			
			// Fetch the database adapter
			$databaseAdapter = $this->provider->getDatabaseAdapter();
			
			// Step 1: Fetch the entity map from the Entity Store
			$entityMap = $this->getEntityStore()->getEntityMap();
			
			if (empty($entityMap)) {
				$this->output->writeLn("No entity classes found.");
				return 1;
			}
			
			// Step 2: Analyze changes between entities and database
			// Instantiate the schema analyzer
			$entitySchemaAnalyzer = new EntitySchemaAnalyzer($databaseAdapter, $this->getEntityStore());
			
			// And perform the analysis
			$allChanges = $entitySchemaAnalyzer->analyzeEntityChanges($entityMap);
			
			// Step 3: Report detected changes to the user
			if (empty($allChanges)) {
				$this->output->writeLn("No changes detected. Migration file not created.");
				return 0;
			}
			
			$this->output->writeLn("\n Changes detected:");
			
			foreach ($allChanges as $tableName => $changes) {
				if (!empty($changes['table_not_exists'])) {
					$this->output->writeLn(" ✓ New table: {$tableName}");
				}
				
				foreach ($changes['added'] as $columnName => $definition) {
					$this->output->writeLn(" ✓ New column: {$tableName}.{$columnName}");
				}
				
				foreach ($changes['modified'] as $columnName => $diff) {
					$description = $this->describeColumnChange($diff);
					$this->output->writeLn(" ✓ Modified column: {$tableName}.{$columnName}{$description}");
				}
				
				foreach ($changes['deleted'] as $columnName => $definition) {
					$this->output->writeLn(" ✓ Dropped column: {$tableName}.{$columnName}");
				}
				
				foreach ($changes['indexes']['added'] as $indexName => $indexConfig) {
					$this->output->writeLn(" ✓ New index: {$tableName}.{$indexName}");
				}
				
				foreach ($changes['indexes']['modified'] as $indexName => $indexConfig) {
					$this->output->writeLn(" ✓ Modified index: {$tableName}.{$indexName}");
				}
				
				foreach ($changes['indexes']['deleted'] as $indexName => $indexConfig) {
					$this->output->writeLn(" ✓ Dropped index: {$tableName}.{$indexName}");
				}
			}
			
			$this->output->writeLn("");
			
			// Step 4: Generate a migration file based on changes.
			$migrationBuilder = new PhinxMigrationBuilder($databaseAdapter, $this->migrationsPath);
			$result = $migrationBuilder->generateMigrationFile($allChanges);
			
			if (!$result['success']) {
				$this->output->writeLn($result['message']);
				return 1;
			}
			
			$path = $result['path'] ?? '';
			$this->output->writeLn(" Success! Created: " . $path);
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
		 * Produce a human-readable summary of what changed in a modified column
		 *
		 * @param array{
		 *     from?: ColumnDefinition,
		 *     to?: ColumnDefinition,
		 *     changes?: array<string, array{from: mixed, to: mixed}>
		 * } $diff
		 *
		 * @return string Parenthesised description, or empty string if no description can be inferred
		 */
		private function describeColumnChange(array $diff): string {
			$from = $diff['from'] ?? [];
			$to = $diff['to'] ?? [];
			$parts = [];
			
			if (($from['type'] ?? null) !== ($to['type'] ?? null)) {
				$parts[] = "type changed to " . ($to['type'] ?? 'unknown');
			}
			
			if (($from['limit'] ?? null) !== ($to['limit'] ?? null)) {
				$toLimit = $to['limit'] ?? null;
				$limitStr = is_array($toLimit) ? json_encode($toLimit) : (string)($toLimit ?? 'default');
				$parts[] = "length changed to " . $limitStr;
			}
			
			if (($from['nullable'] ?? null) !== ($to['nullable'] ?? null)) {
				$parts[] = ($to['nullable'] ?? false) ? "now nullable" : "now not nullable";
			}
			
			return empty($parts) ? "" : " (" . implode(", ", $parts) . ")";
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