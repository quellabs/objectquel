<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Shows which migrations have been applied and which are pending.
	 */
	class StatusCommand extends AbstractMigrationCommand {

		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'quel:migrate:status';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Show which migrations have been applied and which are pending.';
		}

		/**
		 * Shows the migration status.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$runner = $this->makeRunner();

			if ($runner === null) {
				return 1;
			}

			return $this->renderStatus($runner);
		}
	}
