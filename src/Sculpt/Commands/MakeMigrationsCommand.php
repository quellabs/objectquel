<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntitySchemaAnalyzer;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	use Quellabs\ObjectQuel\Sculpt\Helpers\QuelMigrationBuilder;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	
	/**
	 * MakeMigrationsCommand - CLI command for generating database migrations
	 *
	 * Detects differences between entity definitions and the current database schema,
	 * then produces an ObjectQuel migration file to synchronize the two.
	 *
	 * @phpstan-import-type ColumnModification from SculptTypes
	 * @phpstan-import-type EntityChangeSet from SculptTypes
	 */
	class MakeMigrationsCommand extends MakeCommandBase {
		
		/** @var string Path to the migrations folder */
		private string $migrationsPath;
		
		/**
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ServiceProvider $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
			$this->migrationsPath = $this->configuration->getMigrationsPath();
		}
		
		/**
		 * Execute the migration generation command.
		 * Analyzes entity/schema differences and writes a migration file.
		 * @param ConfigurationManager $config Parameters passed to the command
		 * @return int Exit code (0 for success, 1 for failure)
		 * @throws AnnotationReaderException
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("");
			$this->output->writeLn(" ██████╗ ██╗   ██╗███████╗██╗");
			$this->output->writeLn("██╔═══██╗██║   ██║██╔════╝██║");
			$this->output->writeLn("██║   ██║██║   ██║█████╗  ██║");
			$this->output->writeLn("██║▄▄ ██║██║   ██║██╔══╝  ██║");
			$this->output->writeLn("╚██████╔╝╚██████╔╝███████╗███████╗");
			$this->output->writeLn(" ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝");
			$this->output->writeLn("");
			$this->output->writeLn("Generating migrations...");
			
			// Fetch entity map; abort if no entities are registered
			$entityMap = $this->getEntityStore()->getEntityMap();
			
			if (empty($entityMap)) {
				$this->output->writeLn("No entity classes found.");
				return 1;
			}
			
			// Detect differences between entity definitions and the live database schema
			/** @var ServiceProvider $serviceProvider */
			$serviceProvider = $this->provider;
			$databaseAdapter = $serviceProvider->getDatabaseAdapter();
			$platform = new PlatformCapabilities($databaseAdapter);
			$analyzer = new EntitySchemaAnalyzer($databaseAdapter, $this->getEntityStore(), $platform);
			$allChanges = $analyzer->analyzeEntityChanges($entityMap);
			
			if (empty($allChanges)) {
				$this->output->writeLn("No changes detected. Migration file not created.");
				return 0;
			}
			
			// Report every detected change to the user before writing anything
			$this->printChangeSummary($allChanges);
			
			// Generate the migration file and report the result
			$migrationBuilder = new QuelMigrationBuilder($databaseAdapter, $this->migrationsPath, $platform);
			$result = $migrationBuilder->generateMigrationFile($allChanges);
			
			if (!$result['success']) {
				$this->output->writeLn($result['message']);
				return 1;
			}
			
			$this->output->writeLn("Success! Created file: " . ($result['path'] ?? ''));
			return 0;
		}
		
		/**
		 * Get the command signature used to invoke it from the CLI
		 * @return string
		 */
		public function getSignature(): string {
			return "make:migrations";
		}
		
		/**
		 * One-line description shown in the command list
		 * @return string
		 */
		public function getDescription(): string {
			return "Generate database migrations based on entity changes";
		}
		
		/**
		 * Extended help text shown when the user passes --help
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Generate a database migration file by comparing entity definitions with the
    current database schema and producing an ObjectQuel migration to synchronize them.

USAGE:
    php sculpt make:migrations

ARGUMENTS:
    None

NOTES:
    - Requires a valid database connection and configured entity path
    - Only structural changes are detected (columns, types, indexes)
    - No migration file is written when no differences are found
HELP;
		}
		
		// -------------------------------------------------------------------------
		// Private helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Print a human-readable summary of all detected schema changes
		 * @param array<string, EntityChangeSet> $allChanges Keyed by table name
		 * @return void
		 */
		private function printChangeSummary(array $allChanges): void {
			$this->output->writeLn("\n Changes detected:");
			
			foreach ($allChanges as $tableName => $changes) {
				// New table
				if (!empty($changes['table_not_exists'])) {
					$this->output->writeLn(" ✓ New table: {$tableName}");
				}
				
				// Column-level changes
				foreach ($changes['added'] as $col => $def) {
					$this->output->writeLn(" ✓ New column: {$tableName}.{$col}");
				}
				
				foreach ($changes['modified'] as $col => $diff) {
					$this->output->writeLn(" ✓ Modified column: {$tableName}.{$col}" . $this->describeColumnChange($diff));
				}
				
				foreach ($changes['deleted'] as $col => $def) {
					$this->output->writeLn(" ✓ Dropped column: {$tableName}.{$col}");
				}
				
				// Index-level changes
				foreach ($changes['indexes']['added'] as $idx => $cfg) {
					$this->output->writeLn(" ✓ New index: {$tableName}.{$idx}");
				}
				
				foreach ($changes['indexes']['modified'] as $idx => $cfg) {
					$this->output->writeLn(" ✓ Modified index: {$tableName}.{$idx}");
				}
				
				foreach ($changes['indexes']['deleted'] as $idx => $cfg) {
					$this->output->writeLn(" ✓ Dropped index: {$tableName}.{$idx}");
				}

				// Foreign-key-level changes
				foreach ($changes['foreignKeys']['added'] as $fk => $cfg) {
					$this->output->writeLn(" ✓ New foreign key: {$tableName}.{$fk}");
				}

				foreach ($changes['foreignKeys']['modified'] as $fk => $cfg) {
					$this->output->writeLn(" ✓ Modified foreign key: {$tableName}.{$fk}");
				}

				foreach ($changes['foreignKeys']['deleted'] as $fk => $cfg) {
					$this->output->writeLn(" ✓ Dropped foreign key: {$tableName}.{$fk}");
				}

				// Primary-key change — at most one per table, unlike every
				// other facet above. Narrowed via a single local variable
				// (rather than re-reading $changes['primaryKey'] after
				// extracting just its 'action') so checking 'action' here
				// also narrows which of 'columns'/'from' are available.
				$primaryKeyChange = $changes['primaryKey'] ?? ['action' => null];

				if ($primaryKeyChange['action'] === 'set') {
					$columns = implode(', ', $primaryKeyChange['columns']);
					$this->output->writeLn(" ✓ Primary key changed: {$tableName} ({$columns})");
				} elseif ($primaryKeyChange['action'] === 'drop') {
					$this->output->writeLn(" ✓ Primary key dropped: {$tableName}");
				}
			}
			
			$this->output->writeLn("");
		}
		
		/**
		 * Produce a parenthesised description of what changed in a modified column,
		 * e.g. " (type changed to varchar, now nullable)".
		 * Returns an empty string when nothing describable changed.
		 * @param array $diff
		 * @phpstan-param ColumnModification $diff
		 * @return string
		 */
		private function describeColumnChange(array $diff): string {
			$from = $diff['from'];
			$to = $diff['to'];
			$parts = [];

			if ($from->type !== $to->type) {
				$parts[] = "type changed to " . $to->type;
			}

			if ($from->limit !== $to->limit) {
				$toLimit = $to->limit;
				$parts[] = "length changed to " . (is_array($toLimit) ? json_encode($toLimit) : (string)($toLimit ?? 'default'));
			}

			if ($from->nullable !== $to->nullable) {
				$parts[] = $to->nullable ? "now nullable" : "now not nullable";
			}
			
			return empty($parts) ? "" : " (" . implode(", ", $parts) . ")";
		}
	}