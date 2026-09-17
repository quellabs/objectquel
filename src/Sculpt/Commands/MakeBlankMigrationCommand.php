<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * MakeBlankMigrationCommand - Create a blank migration skeleton for hand-written migrations
	 *
	 * Writes a `<YmdHis>_<Name>.php` file with empty up()/down() bodies — for
	 * migrations that aren't derived from an entity diff (data backfills,
	 * one-off DDL make:migrations can't infer, etc.). The author fills in
	 * both bodies with query(...) calls, same as a generated migration
	 * (see AbstractMigration).
	 */
	class MakeBlankMigrationCommand extends MakeCommandBase {

		public function getSignature(): string {
			return 'make:blank-migration';
		}

		public function getDescription(): string {
			return 'Create a blank migration file for hand-written schema or data changes';
		}

		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Creates a blank migration file with empty up()/down() bodies, named
    <timestamp>_<Name>.php in the configured migrations directory. Use this
    for migrations that aren't derived from an entity diff — data backfills,
    or DDL make:migrations can't infer on its own.

USAGE:
    php sculpt make:blank-migration <Name>

ARGUMENTS:
    Name    The migration's class name (PascalCase, e.g. BackfillUserStatus)

EXAMPLES:
    php sculpt make:blank-migration BackfillUserStatus

NOTES:
    - The generated class extends AbstractMigration; fill in up()/down() with
      \$this->query(...) calls using ObjectQuel DDL/DML
    - The leading <timestamp> is this command's own run time (YmdHis) and
      also becomes the migration's version once applied
HELP;
		}

		/**
		 * Execute the migration skeleton creation.
		 * @param ConfigurationManager $config Command configuration and arguments
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				$name = $config->getPositional(0);

				if ($name === null) {
					$this->output->error("Missing required argument: Name. Usage: php sculpt make:blank-migration <Name>");
					return 1;
				}

				if (!$this->isValidPhpIdentifier($name)) {
					$this->output->error("Invalid migration name '{$name}'. Must be a valid PHP class name.");
					return 1;
				}

				$migrationsPath = $this->configuration->getMigrationsPath();

				if ($migrationsPath === '') {
					$this->output->error("No migrations path configured (migrations_path).");
					return 1;
				}

				$this->ensureDirectoryExists($migrationsPath);

				$version = date('YmdHis');
				$filePath = rtrim($migrationsPath, '/\\') . "/{$version}_{$name}.php";

				if (file_exists($filePath)) {
					$this->output->error("Migration file already exists: {$filePath}");
					return 1;
				}

				if (file_put_contents($filePath, $this->generateMigrationContent($name)) === false) {
					throw new \RuntimeException("Failed to create migration file: {$filePath}");
				}

				$this->output->writeLn("Success! Created file: {$filePath}");
				return 0;
			} catch (\Throwable $e) {
				$this->output->error("Error: {$e->getMessage()}");
				return 1;
			}
		}

		/**
		 * @param string $className
		 * @return string Complete PHP file content for the blank migration
		 */
		private function generateMigrationContent(string $className): string {
			return <<<PHP
<?php

    use Quellabs\ObjectQuel\Migration\AbstractMigration;

    class {$className} extends AbstractMigration {

        public function up(): void {
        }

        public function down(): void {
        }
    }

PHP;
		}
	}
