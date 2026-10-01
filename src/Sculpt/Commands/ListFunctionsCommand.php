<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * ListFunctionsCommand - CLI command for listing all EQUEL functions
	 *
	 * Reads the connected database's function catalog and displays every EQUEL function
	 * with its parameters and return type: an abstract ObjectQuel column type, or "void"
	 * for a function with no return value — EQUEL's own return type, exactly as written in
	 * `define function name(...) void { ... }`. EQUEL has only one kind of routine, a
	 * function; the function/procedure split some engines use internally is not exposed
	 * here.
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
			return "List all EQUEL functions and their return types.";
		}

		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Lists every EQUEL function in the connected database's default schema,
    with its parameters and return type. Both are shown as ObjectQuel's
    abstract column types, not the engine-native ones, and the return type
    may be `void`, mirroring EQUEL's own `define function name(...)
    returnType { ... }` syntax. EQUEL only has functions — the
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
						$function['isProcedure'] ? 'void' : ($function['returnType'] ?? 'unknown'),
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
		 * @param list<array{name: string, type: ?string}> $parameters
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
