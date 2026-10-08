<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * ListTriggersCommand - CLI command for listing all EQUEL trigger bindings
	 *
	 * Reads the connected database's trigger catalog and displays every live binding —
	 * one created by `after (append to|replace|delete) <range> call <routine>(args)`. An
	 * ordinary trigger, created outside ObjectQuel, is not shown: it isn't calling a
	 * tfunction, so it isn't one of ours to begin with.
	 *
	 * Each binding's alias is shown as-is — either the name given with `as <alias>`, or an
	 * opaque generated one, when the binding was created without naming it. Either way,
	 * `destroy trigger <range> <alias>` takes it straight from this listing.
	 *
	 * Supported dialects: MySQL, MariaDB, PostgreSQL, SQL Server. SQLite has no triggers and
	 * is reported as an error.
	 */
	class ListTriggersCommand extends MakeCommandBase {

		/**
		 * @param ConsoleInput $input Console input handler
		 * @param ConsoleOutput $output Console output handler
		 * @param ServiceProvider $provider Service provider exposing configuration and the DB adapter
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}

		/**
		 * Returns the Sculpt command signature used to invoke this command.
		 * @return string
		 */
		public function getSignature(): string {
			return "quel:list-triggers";
		}

		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "List all EQUEL trigger bindings and their aliases.";
		}

		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Lists every trigger binding in the connected database's default
    schema — one created by `after ... call ...`. A trigger created
    outside ObjectQuel is not listed, since it isn't calling a
    tfunction.

    The Alias column is what `destroy trigger <range> <alias>` takes: the
    name given with `as <alias>` when the binding was created, or an
    opaque generated one otherwise. This is the only place a generated
    alias is shown — it isn't returned by the bind statement itself.

USAGE:
    php sculpt quel:list-triggers

ARGUMENTS:
    None

NOTES:
    - Supported on MySQL, MariaDB, PostgreSQL and SQL Server
    - SQLite has no triggers and is reported as an error
HELP;
		}

		/**
		 * Execute the command to list all EQUEL trigger bindings.
		 * @param ConfigurationManager $config The configuration manager instance
		 * @return int Exit code: 0 on success, 1 on any error
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				/** @var ServiceProvider $provider */
				$provider = $this->provider;
				$bindings = $provider->getDatabaseAdapter()->listBindings();

				if (empty($bindings)) {
					$this->output->writeLn("No trigger bindings found.");
					return 0;
				}

				$rows = [];

				foreach ($bindings as $binding) {
					$rows[] = [
						$binding['table'],
						$binding['event']->keyword(),
						$binding['routine'],
						$binding['alias'],
					];
				}

				$this->output->table(['Table', 'Event', 'Routine', 'Alias'], $rows);
				$this->output->writeLn("");
				$this->output->writeLn(count($rows) . " " . (count($rows) === 1 ? "binding" : "bindings") . " found.");
				return 0;

			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}
