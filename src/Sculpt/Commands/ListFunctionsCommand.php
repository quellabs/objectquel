<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * ListFunctionsCommand - CLI command for listing all functions and procedures callable from EQUEL
	 *
	 * Reads the connected database's function catalog and displays every EQUEL function
	 * as EQUEL sees it: its kind (function or procedure, the same distinction EQUEL uses
	 * to decide whether a call is an expression or a `call` statement) and, for
	 * value-returning functions, the return type normalized to ObjectQuel's abstract
	 * column types rather than the engine's native type names.
	 *
	 * Supported dialects: MySQL, MariaDB, PostgreSQL, SQL Server. SQLite has no stored
	 * functions and is reported as an error.
	 */
	class ListFunctionsCommand extends MakeCommandBase {

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
			return "quel:list-functions";
		}

		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "List all functions and procedures callable from EQUEL.";
		}

		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Lists every EQUEL function in the connected database's default schema:
    functions (value-returning, called from an expression) and procedures
    (void, called via a `call` statement). A function's return type is shown
    as ObjectQuel's abstract column type, not the engine-native type name,
    so the output reads the same regardless of dialect.

    A name can appear twice if it exists as both a function and a procedure —
    MySQL and MariaDB give the two kinds separate namespaces.

USAGE:
    php sculpt quel:list-functions

ARGUMENTS:
    None

NOTES:
    - Supported on MySQL, MariaDB, PostgreSQL and SQL Server
    - SQLite has no stored functions and is reported as an error
HELP;
		}

		/**
		 * Execute the command to list all EQUEL functions.
		 * @param ConfigurationManager $config The configuration manager instance
		 * @return int Exit code: 0 on success, 1 on any error
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				/** @var ServiceProvider $provider */
				$provider = $this->provider;
				$functions = $provider->getDatabaseAdapter()->listRoutines();

				if (empty($functions)) {
					$this->output->writeLn("No functions or procedures found.");
					return 0;
				}

				$rows = [];

				foreach ($functions as $function) {
					$rows[] = [
						$function['name'],
						$function['isProcedure'] ? 'procedure' : 'function',
						$function['isProcedure'] ? '-' : ($function['returnType'] ?? 'unknown'),
					];
				}

				$this->output->table(['Name', 'Kind', 'Return Type'], $rows);
				$this->output->writeLn(count($rows) . " " . (count($rows) === 1 ? "function" : "functions") . " found.");
				return 0;

			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}
