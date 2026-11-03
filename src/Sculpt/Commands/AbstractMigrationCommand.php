<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Migration\MigrationLocator;
	use Quellabs\ObjectQuel\Migration\MigrationRepository;
	use Quellabs\ObjectQuel\Migration\MigrationRunner;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\Sculpt\Contracts\CommandBase;

	/**
	 * Shared plumbing for quel:migrate* commands: builds the MigrationRunner
	 * from the application's ObjectQuel configuration, and renders the
	 * version/name/applied-at status table they all report.
	 */
	abstract class AbstractMigrationCommand extends CommandBase {

		/**
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ServiceProvider $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
		}

		/**
		 * Builds a MigrationRunner wired to the application's configured
		 * connection, migrations path, and tracking table.
		 * @return MigrationRunner|null Null when the provider isn't the ObjectQuel ServiceProvider; the error is already printed
		 */
		protected function makeRunner(): ?MigrationRunner {
			$serviceProvider = $this->getProvider();

			if (!$serviceProvider instanceof ServiceProvider) {
				$this->output->error("Unable to fetch ObjectQuel configuration");
				return null;
			}

			$configuration = $serviceProvider->getConfiguration();
			$entityManager = $serviceProvider->getEntityManager();
			$platform = new PlatformCapabilities($serviceProvider->getDatabaseAdapter());
			$repository = new MigrationRepository($entityManager, $configuration->getMigrationsTable());
			$locator = new MigrationLocator($entityManager, $configuration->getMigrationsPath());

			return new MigrationRunner($entityManager, $platform, $repository, $locator);
		}

		/**
		 * Prints every migration's version, name, and applied-at timestamp
		 * (or "pending") as a table.
		 * @param MigrationRunner $runner
		 * @return int Exit code (0 for success)
		 */
		protected function renderStatus(MigrationRunner $runner): int {
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
	}
