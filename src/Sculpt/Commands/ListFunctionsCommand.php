<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * ListFunctionsCommand - CLI command for listing all EQUEL functions
	 *
	 * Reads the connected database's function catalog and displays every routine carrying
	 * ObjectQuel's own JSON metadata (RoutineMetadata) — i.e. one `define function` deployed.
	 * A routine without that metadata was created outside ObjectQuel, or predates the
	 * metadata, and isn't shown: its EQUEL-level return type and parameter shape can't be
	 * recovered from the native catalog alone. The return type shown is the metadata's own:
	 * `void`, `trigger`, or the declared scalar type, exactly as written in
	 * `define function name(...) returnType { ... }`. The function/procedure split some
	 * engines use internally is not exposed here.
	 *
	 * Supported dialects: MySQL, MariaDB, PostgreSQL, SQL Server. SQLite has no stored
	 * functions and is reported as an error.
	 *
	 * @phpstan-import-type RoutineParameter from DatabaseAdapter
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
			return "List all EQUEL functions and their return types.";
		}

		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Lists every function in the connected database's default schema that
    carries ObjectQuel's own metadata, i.e. was deployed by `define
    function`. A function or procedure created outside ObjectQuel, or
    deployed before this metadata existed, is not listed.

    Return type is shown exactly as EQUEL declared it — `void`, `trigger`,
    or an abstract ObjectQuel column type. Parameter types are shown as
    abstract column types too. EQUEL only has functions — the
    function/procedure split some engines use internally is not exposed
    here.

    A name can appear twice if the database has it as both a value-returning
    and a void function — MySQL and MariaDB give the two separate
    namespaces.

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
					$this->output->writeLn("No functions found.");
					return 0;
				}

				$rows = [];

				foreach ($functions as $function) {
					$rows[] = [
						$function['name'],
						$this->formatParameters($function['parameters']),
						$function['returnType'],
					];
				}

				$this->output->table(['Name', 'Parameters', 'Return Type'], $rows);
				$this->output->writeLn("");
				$this->output->writeLn(count($rows) . " " . (count($rows) === 1 ? "function" : "functions") . " found.");
				return 0;

			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}

		/**
		 * Formats a function's parameter list as "type name, type name", matching EQUEL's own
		 * `(type name, ...)` declaration order. A parameter whose type ObjectQuel doesn't
		 * recognize shows as "unknown", the same fallback used for an unrecognized return type.
		 * @param list<RoutineParameter> $parameters
		 * @return string Comma-joined "type name" pairs, or "-" when the function takes none
		 */
		private function formatParameters(array $parameters): string {
			if (empty($parameters)) {
				return '-';
			}

			return implode(', ', array_map(
				static fn(array $parameter): string => ($parameter['type'] ?? 'unknown') . ' ' . $parameter['name'],
				$parameters
			));
		}
	}
