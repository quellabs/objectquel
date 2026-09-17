<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Scans a migrations directory for `<YmdHis>_<ClassName>.php` files,
	 * requires and instantiates each one, and returns them sorted by
	 * version (ascending) — the same `<YmdHis>_<ClassName>.php` naming
	 * scheme Phinx-style migration tools already use (see
	 * AbstractMigration's class docblock), kept for sortable, diffable
	 * filenames.
	 *
	 * Each located class is expected to be declared in the global
	 * namespace (no `namespace` statement in the migration file itself),
	 * matching the Phinx-style convention this scheme is carried over from.
	 */
	class MigrationLocator {

		private const string FILENAME_PATTERN = '/^(\d{14})_([A-Za-z_][A-Za-z0-9_]*)\.php$/';

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly string $migrationsPath,
		) {
		}

		/**
		 * @return list<LocatedMigration> Sorted by version, ascending
		 */
		public function locate(): array {
			if (!is_dir($this->migrationsPath)) {
				return [];
			}

			$migrations = [];

			foreach (glob(rtrim($this->migrationsPath, '/\\') . '/*.php') ?: [] as $file) {
				$migration = $this->loadMigrationFile($file);

				if (isset($migrations[$migration->version])) {
					throw new \RuntimeException(
						"Duplicate migration version {$migration->version}: both " .
						"'{$migrations[$migration->version]->name}' and '{$migration->name}' declare it"
					);
				}

				$migrations[$migration->version] = $migration;
			}

			ksort($migrations);
			return array_values($migrations);
		}

		/**
		 * @throws \RuntimeException If the filename doesn't match the naming
		 *         convention, or the class it names doesn't exist or isn't
		 *         an AbstractMigration
		 */
		private function loadMigrationFile(string $file): LocatedMigration {
			$basename = basename($file);

			if (!preg_match(self::FILENAME_PATTERN, $basename, $matches)) {
				throw new \RuntimeException(
					"Migration file '{$basename}' doesn't match the required '<YmdHis>_<ClassName>.php' naming convention"
				);
			}

			[, $version, $className] = $matches;

			require_once $file;

			if (!class_exists($className, false)) {
				throw new \RuntimeException(
					"Migration file '{$basename}' does not declare the expected class '{$className}'"
				);
			}

			if (!is_subclass_of($className, AbstractMigration::class)) {
				throw new \RuntimeException(
					"Class '{$className}' in '{$basename}' must extend " . AbstractMigration::class
				);
			}

			/** @var AbstractMigration $instance */
			$instance = new $className($this->entityManager);

			return new LocatedMigration((int)$version, $className, $instance);
		}
	}
