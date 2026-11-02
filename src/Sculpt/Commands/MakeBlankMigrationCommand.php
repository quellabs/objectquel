<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\OrmEngineResolver;

	/**
	 * MakeBlankMigrationCommand - Create a blank migration skeleton for hand-written migrations
	 *
	 * Writes a `<YmdHis>_<Name>.php` file with empty up()/down() bodies — for
	 * migrations that aren't derived from an entity diff (data backfills,
	 * one-off DDL make:migrations can't infer, etc.). The author fills in
	 * both bodies with query(...) calls, same as a generated migration
	 * (see AbstractMigration). Name is optional; when omitted, it defaults
	 * to "Migration<YmdHis>" so the run time's own version stamp doubles
	 * as the class name, guaranteeing it's unique across the migrations
	 * directory the way MigrationLocator requires.
	 */
	class MakeBlankMigrationCommand extends MakeCommandBase {

		/**
		 * Get the command signature used to invoke it from the CLI: the short
		 * 'make:blank-migration' when ObjectQuel is the only ORM installed, or
		 * the 'quel:' prefixed form when another ORM's own make:blank-migration
		 * command would otherwise collide with it.
		 * @return string
		 */
		public function getSignature(): string {
			$prefix = OrmEngineResolver::count() > 1 ? 'quel:' : '';
			return $prefix . 'make:blank-migration';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Create a blank migration file for hand-written schema or data changes';
		}

		/**
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Creates a blank migration file with empty up()/down() bodies, named
    <timestamp>_<Name>.php in the configured migrations directory. Use this
    for migrations that aren't derived from an entity diff — data backfills,
    or DDL make:migrations can't infer on its own.

USAGE:
    php sculpt {$this->getSignature()} [Name]

ARGUMENTS:
    Name    Optional. The migration's class name (PascalCase, e.g.
            BackfillUserStatus). Defaults to Migration<timestamp> when omitted.

EXAMPLES:
    php sculpt {$this->getSignature()}
    php sculpt {$this->getSignature()} BackfillUserStatus

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
				$version = date('YmdHis');
				$name = $config->getPositional(0) ?? "Migration{$version}";

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
