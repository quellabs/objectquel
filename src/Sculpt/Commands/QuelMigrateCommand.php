<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Phinx\Config\Config;
	use Phinx\Migration\Manager;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Symfony\Component\Console\Input\ArrayInput;
	use Symfony\Component\Console\Output\BufferedOutput;
	
	/**
	 * ExecuteMigrationsCommand - CLI command for managing database migrations
	 */
	class QuelMigrateCommand extends CommandBase {
		
		private string $environment;
		
		/**
		 * QuelMigrateCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ProviderInterface|null $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ProviderInterface $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->environment = 'development';
		}
		
		/**
		 * Execute the command
		 * @param ConfigurationManager $config Optional parameters passed to the command
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			// Create a Phinx configuration
			$phinxConfig = new Config($this->getProvider()->createPhinxConfig());
			
			// Create the manager with buffered output to capture all output
			// Always use the 'development' environment since that's all our config supports
			$inputArgs = $this->prepareInputArgs($config);
			$input = new ArrayInput($inputArgs);
			$bufferedOutput = new BufferedOutput();
			$manager = new Manager($phinxConfig, $input, $bufferedOutput);
			
			try {
				// Determine which operation to perform based on flags
				if ($config->hasFlag('rollback')) {
					$result = $this->performRollback($manager, $config);
				} elseif ($config->hasFlag('status')) {
					$result = $this->showStatus($manager);
				} else {
					$result = $this->runMigrations($manager, $config);
				}
				
				// Get any output from the buffered output and display it
				$outputContent = $bufferedOutput->fetch();
				
				if (!empty($outputContent)) {
					$this->output->write($outputContent);
				}
				
				return $result;
			} catch (\Exception $e) {
				$this->output->error("Migration error: " . $e->getMessage());
				return 1;
			}
		}
		
		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return "quel:migrate";
		}
		
		/**
		 * Get a short description of what the command does
		 * @return string Command description
		 */
		public function getDescription(): string {
			return "Manage database migrations: run, rollback, or check status.";
		}
		
		/**
		 * Get detailed help information for the command
		 * @return string Command help text
		 */
		public function getHelp(): string {
			return <<<HELP
Manages database migrations for your application. Makes use of Phinx under the hood.

Usage:
  quel:migrate [options]

Options:
  --rollback           Roll back migrations instead of running them
  --status             Show migration status instead of running migrations
  --target=<version>   Target a specific migration version
  --steps=<number>     Number of migrations to roll back (default: 1)
  --dry-run, -d        Run in dry-run mode without making actual database changes
  --force, -f          Skip confirmation prompts (useful for CI/CD pipelines)

Examples:
  vendor/bin/sculpt quel:migrate                          # Run all pending migrations (with confirmation)
  vendor/bin/sculpt quel:migrate --force                  # Run all pending migrations without confirmation
  vendor/bin/sculpt quel:migrate --rollback               # Roll back the most recent migration (with confirmation)
  vendor/bin/sculpt quel:migrate --rollback --steps=3     # Roll back the last 3 migrations (with confirmation)
  vendor/bin/sculpt quel:migrate --status                 # Show migration status
  vendor/bin/sculpt quel:migrate --target=20230415000000  # Migrate to a specific version (with confirmation)
  vendor/bin/sculpt quel:migrate --dry-run                # Preview migrations without applying them
HELP;
		}
		
		/**
		 * Prepare input arguments for Phinx commands
		 * @param ConfigurationManager $config
		 * @return array
		 */
		private function prepareInputArgs(ConfigurationManager $config): array {
			$args = [];
			
			// Add dry-run flag if specified
			if ($config->hasFlag('dry-run') || $config->hasFlag('d')) {
				$args['--dry-run'] = true;
			}
			
			return $args;
		}
		
		/**
		 * Get pending migrations to display to the user
		 * @param Manager $manager
		 * @return array Array of pending migrations
		 */
		private function getPendingMigrations(Manager $manager): array {
			// Get all migrations
			$migrations = $manager->getMigrations($this->environment);
			
			// Get all migrated versions
			$adapter = $manager->getEnvironment($this->environment)->getAdapter();
			$versions = $adapter->getVersions();
			
			// Filter to get only pending migrations
			return array_filter($migrations, function ($version) use ($versions) {
				return !in_array($version, $versions);
			}, ARRAY_FILTER_USE_KEY);
		}
		
		/**
		 * Display pending migrations and ask for confirmation
		 * @param Manager $manager Migration Manager instance
		 * @param ConfigurationManager $config Configuration Manager containing runtime flags
		 * @return bool True if user confirms or force/dry-run flags are set, false otherwise
		 */
		private function confirmMigrations(Manager $manager, ConfigurationManager $config): bool {
			// Get all migrations that haven't been applied yet
			$pending = $this->getPendingMigrations($manager);
			$count = count($pending);
			
			// If no pending migrations, nothing to do
			if ($count === 0) {
				$this->output->writeLn("<info>No pending migrations found.</info>");
				return false;
			}
			
			// Check for force flag to skip confirmation
			// This allows automated scripts to run migrations without user interaction
			if ($config->hasFlag('force') || $config->hasFlag('f')) {
				return true;
			}
			
			// If in dry-run mode, we can proceed without confirmation
			// Dry-run will only display what would happen without making actual changes
			if ($config->hasFlag('dry-run') || $config->hasFlag('d')) {
				return true;
			}
			
			// Display pending migrations with proper singular/plural form
			$this->output->writeLn("<info>{$count} pending " . ($count === 1 ? "migration" : "migrations") . " found:</info>");
			$this->output->writeLn(""); // Empty line for better readability
			
			// Build table rows with migration information
			$rows = [];
			foreach ($pending as $version => $migration) {
				// Each row contains the version number and fully qualified class name
				$rows[] = [$version, get_class($migration)];
			}
			
			// Display migrations in a formatted table for better readability
			$this->output->table(['Version', 'Migration Name'], $rows);
			
			// Ask for confirmation and return user's choice
			// The migration will only proceed if the user confirms with 'yes'
			$this->output->writeLn("");
			return $this->input->confirm("Do you want to run these migrations?", false);
		}
		
		/**
		 * Run migrations to update database schema
		 * @param Manager $manager Migration Manager responsible for executing migrations
		 * @param ConfigurationManager $config Configuration with runtime options and flags
		 * @return int Exit code (0 for success)
		 */
		private function runMigrations(Manager $manager, ConfigurationManager $config): int {
			// First check if there are pending migrations and get user confirmation
			// This will return false if no migrations exist or user cancels the operation
			if (!$this->confirmMigrations($manager, $config)) {
				$this->output->writeLn("<info>Migration operation canceled.</info>");
				return 0; // Return success code since this is not an error
			}
			
			// Output message
			$this->output->writeLn("<info>Running migrations...</info>");
			
			// Check if this is a dry run (simulation only)
			// This is useful for previewing what changes will be made without actually applying them
			if ($config->hasFlag('dry-run') || $config->hasFlag('d')) {
				$this->output->writeLn("<dim>Dry run mode - no database changes will be made.</dim>");
			}
			
			// Check if a specific target version was requested
			// This allows migrating to a specific version instead of the latest one
			$target = $config->get('target');
			
			if ($target) {
				// Migrate to the specific target version
				$this->output->writeLn("<info>Migrating to version: {$target}</info>");
				$manager->migrate($this->environment, $target);
			} else {
				// No target specified, migrate to the latest version
				$manager->migrate($this->environment);
			}
			
			// All migrations completed without errors
			$this->output->writeLn("<success>Migration completed successfully.</success>");
			return 0; // Return success exit code
		}
		
		/**
		 * Roll back migrations
		 * @param Manager $manager
		 * @param ConfigurationManager $config
		 * @return int
		 */
		private function performRollback(Manager $manager, ConfigurationManager $config): int {
			$steps = (int)$config->get('steps', 1);
			$target = $config->get('target');
			
			// Prompt for confirmation if not in force mode or dry-run mode
			if (!$config->hasFlag('force') && !$config->hasFlag('f') && !$config->hasFlag('dry-run') && !$config->hasFlag('d')) {
				if ($target) {
					$message = "Are you sure you want to roll back to version {$target}?";
				} else {
					$message = "Are you sure you want to roll back {$steps} " . ($steps === 1 ? "migration" : "migrations") . "?";
				}
				
				// Show cancel message if the user entered 'n'
				if (!$this->input->confirm($message, false)) {
					$this->output->writeLn("<info>Rollback operation canceled.</info>");
					return 0;
				}
			}
			
			// Show message
			$this->output->writeLn("<info>Rolling back migrations...</info>");
			
			// Check for dry run
			if ($config->hasFlag('dry-run') || $config->hasFlag('d')) {
				$this->output->writeLn("<dim>Dry run mode - no database changes will be made.</dim>");
			}
			
			// Get the target version and steps
			if ($target) {
				$this->output->writeLn("<info>Rolling back to version: {$target}</info>");
				$manager->rollback($this->environment, $target);
			} else {
				$this->output->writeLn("<info>Rolling back {$steps} " . ($steps === 1 ? "migration" : "migrations") . "</info>");
				$manager->rollback($this->environment, null, $steps);
			}
			
			// Output success message
			$this->output->success("Rollback completed successfully.");
			return 0;
		}
		
		/**
		 * Show migration status
		 * @param Manager $manager
		 * @return int
		 */
		private function showStatus(Manager $manager): int {
			// Show status
			$this->output->writeLn("<info>Migration Status:</info>");
			
			// Instead of printing directly, capture the output from Phinx
			$manager->printStatus($this->environment);
			return 0;
		}
	}