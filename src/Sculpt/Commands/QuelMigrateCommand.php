<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Migration\LocatedMigration;
	use Quellabs\ObjectQuel\Migration\MigrationLocator;
	use Quellabs\ObjectQuel\Migration\MigrationRepository;
	use Quellabs\ObjectQuel\Migration\MigrationRunner;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * QuelMigrateCommand - Run, roll back, or check the status of database migrations
	 *
	 * Drives MigrationRunner: real ObjectQuel DDL/DML through
	 * AbstractMigration::query(), tracked in the quel_migrations table —
	 * no Phinx involved (see objectquel-migrations-implementation-plan.md,
	 * Phase 4). Same flags/UX as before; this is a backend swap.
	 *
	 * Dry-run has no engine-level equivalent to Phinx's own --dry-run
	 * anymore (a migration is just a sequence of query() calls run
	 * directly through EntityManager::executeQuery()), so here it means
	 * "list what would run/be rolled back, without invoking any
	 * migration's up()/down() at all" (see Phase 3's dry-run note).
	 */
	class QuelMigrateCommand extends CommandBase {

		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
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
DESCRIPTION:
    Manages database migrations, tracked in the quel_migrations table. Supports
    running pending migrations, rolling back applied ones, and inspecting
    current status. Prompts for confirmation before making any changes unless
    --force is passed.

USAGE:
    php sculpt quel:migrate [options]

OPTIONS:
    --rollback            Roll back migrations instead of running them
    --status              Show migration status without making any changes
    --target=<version>    Target a specific migration version (run or rollback)
    --steps=<number>      Number of migrations to roll back (default: 1)
    --dry-run, -d         Preview what would happen without applying any changes
    --force, -f           Skip confirmation prompts (useful for CI/CD pipelines)

EXAMPLES:
    php sculpt quel:migrate
        Run all pending migrations with confirmation prompt

    php sculpt quel:migrate --force
        Run all pending migrations without confirmation

    php sculpt quel:migrate --rollback
        Roll back the most recent migration with confirmation

    php sculpt quel:migrate --rollback --steps=3
        Roll back the last 3 migrations with confirmation

    php sculpt quel:migrate --target=20230415000000
        Migrate up or down to a specific version with confirmation

    php sculpt quel:migrate --status
        Show which migrations have been applied and which are pending

    php sculpt quel:migrate --dry-run
        Preview pending migrations without applying them
HELP;
		}

		/**
		 * Execute the command
		 * @param ConfigurationManager $config Optional parameters passed to the command
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			$serviceProvider = $this->getProvider();

			if (!$serviceProvider instanceof ServiceProvider) {
				$this->output->error("Unable to fetch ObjectQuel configuration");
				return 1;
			}

			$runner = $this->makeRunner($serviceProvider);

			try {
				if ($config->hasFlag('status')) {
					return $this->showStatus($runner);
				}

				if ($config->hasFlag('rollback')) {
					return $this->performRollback($runner, $config);
				}

				return $this->runMigrations($runner, $config);
			} catch (\Throwable $e) {
				$this->output->error("Migration error: " . $e->getMessage());
				return 1;
			}
		}

		/**
		 * Builds a MigrationRunner wired to the application's configured
		 * connection, migrations path, and tracking table.
		 * @param ServiceProvider $serviceProvider
		 * @return MigrationRunner
		 */
		private function makeRunner(ServiceProvider $serviceProvider): MigrationRunner {
			$configuration = $serviceProvider->getConfiguration();
			$entityManager = $serviceProvider->getEntityManager();
			$platform = new PlatformCapabilities($serviceProvider->getDatabaseAdapter());
			$repository = new MigrationRepository($entityManager, $configuration->getMigrationTable());
			$locator = new MigrationLocator($entityManager, $configuration->getMigrationsPath());

			return new MigrationRunner($entityManager, $platform, $repository, $locator);
		}

		/**
		 * Run migrations to update database schema
		 * @param MigrationRunner $runner
		 * @param ConfigurationManager $config Configuration with runtime options and flags
		 * @return int Exit code (0 for success)
		 */
		private function runMigrations(MigrationRunner $runner, ConfigurationManager $config): int {
			$target = $config->getAsIntOrNull('target');
			$pending = $this->pendingUpToTarget($runner, $target);

			if (!$this->confirmMigrations($pending, $config)) {
				$this->output->writeLn("Migration operation canceled.");
				return 0;
			}

			$this->output->writeLn("Running migrations...");

			if ($this->isDryRun($config)) {
				$this->output->writeLn("Dry run mode - no database changes will be made.");
				$this->output->success("Migration completed successfully.");
				return 0;
			}

			if ($target !== null) {
				$this->output->writeLn("Migrating to version: {$target}");
			}

			$runner->migrate($target);

			$this->output->success("Migration completed successfully.");
			return 0;
		}

		/**
		 * The pending migrations migrate($target) would actually apply,
		 * without invoking any up() — mirrors its target-inclusive condition.
		 * @param MigrationRunner $runner
		 * @param int|null $target
		 * @return list<LocatedMigration>
		 */
		private function pendingUpToTarget(MigrationRunner $runner, ?int $target): array {
			$pending = $runner->getPending();

			if ($target === null) {
				return $pending;
			}

			return array_values(array_filter(
				$pending,
				static fn(LocatedMigration $migration) => $migration->version <= $target
			));
		}

		/**
		 * Display pending migrations and ask for confirmation
		 * @param LocatedMigration[] $pending
		 * @param ConfigurationManager $config Configuration Manager containing runtime flags
		 * @return bool True if user confirms or force/dry-run flags are set, false otherwise
		 */
		private function confirmMigrations(array $pending, ConfigurationManager $config): bool {
			$count = count($pending);

			if ($count === 0) {
				$this->output->writeLn("No pending migrations found.");
				return false;
			}

			if ($config->hasFlag('force') || $config->hasFlag('f')) {
				return true;
			}

			if ($this->isDryRun($config)) {
				$this->output->writeLn("{$count} pending " . ($count === 1 ? "migration" : "migrations") . " would run:");
				$this->output->writeLn("");
				$this->printMigrationTable($pending);
				return true;
			}

			$this->output->writeLn("{$count} pending " . ($count === 1 ? "migration" : "migrations") . " found:");
			$this->output->writeLn("");
			$this->printMigrationTable($pending);
			$this->output->writeLn("");

			return $this->input->confirm("Do you want to run these migrations?", false);
		}

		/**
		 * @param LocatedMigration[] $migrations
		 * @return void
		 */
		private function printMigrationTable(array $migrations): void {
			$rows = [];

			foreach ($migrations as $migration) {
				$rows[] = [$migration->version, $migration->name];
			}

			$this->output->table(['Version', 'Migration Name'], $rows);
		}

		/**
		 * Roll back migrations
		 * @param MigrationRunner $runner
		 * @param ConfigurationManager $config Configuration with runtime options and flags
		 * @return int Exit code (0 for success)
		 */
		private function performRollback(MigrationRunner $runner, ConfigurationManager $config): int {
			$steps = $config->getAsInt('steps', 1);
			$target = $config->getAsIntOrNull('target');
			$force = $config->hasFlag('force') || $config->hasFlag('f');
			$isDryRun = $this->isDryRun($config);

			if (!$force && !$isDryRun) {
				$message = $target !== null
					? "Are you sure you want to roll back to version {$target}?"
					: "Are you sure you want to roll back {$steps} " . ($steps === 1 ? "migration" : "migrations") . "?";

				if (!$this->input->confirm($message, false)) {
					$this->output->writeLn("Rollback operation canceled.");
					return 0;
				}
			}

			$this->output->writeLn("Rolling back migrations...");

			if ($isDryRun) {
				$this->output->writeLn("Dry run mode - no database changes will be made.");
				$this->printMigrationTable($runner->getRollbackCandidates($target, $steps));
				$this->output->success("Rollback completed successfully.");
				return 0;
			}

			if ($target !== null) {
				$this->output->writeLn("Rolling back to version: {$target}");
			} else {
				$this->output->writeLn("Rolling back {$steps} " . ($steps === 1 ? "migration" : "migrations"));
			}

			$runner->rollback($target, $steps);

			$this->output->success("Rollback completed successfully.");
			return 0;
		}

		/**
		 * Show migration status
		 * @param MigrationRunner $runner
		 * @return int Exit code (0 for success)
		 */
		private function showStatus(MigrationRunner $runner): int {
			$this->output->writeLn("Migration Status:");
			$this->output->writeLn("");

			$status = $runner->status();

			if ($status === []) {
				$this->output->writeLn("No migrations found.");
				return 0;
			}

			$rows = [];

			foreach ($status as $entry) {
				$rows[] = [$entry['version'], $entry['name'], $entry['applied_at'] ?? 'pending'];
			}

			$this->output->table(['Version', 'Migration Name', 'Applied At'], $rows);
			return 0;
		}

		/**
		 * Determine whether the command is running in dry-run mode
		 * @param ConfigurationManager $config Configuration with runtime options and flags
		 * @return bool True if --dry-run or -d flag is set
		 */
		private function isDryRun(ConfigurationManager $config): bool {
			return $config->hasFlag('dry-run') || $config->hasFlag('d');
		}
	}
