<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Tracks which migrations have been applied, in a dedicated table
	 * (default `quel_migrations`, see Configuration::getMigrationTable()).
	 *
	 * The table is created via ObjectQuel DDL (`create ...`), but rows are
	 * read/written through the underlying CakePHP query builder instead of
	 * ObjectQuel DML — DML verbs need a declared `range of x is <Entity>`
	 * backed by a real annotated entity, which this internal bookkeeping
	 * table has no need for.
	 *
	 * `version` is never DB-generated: it's always the migration's own
	 * `<YmdHis>` filename timestamp, passed in by recordApplied()'s caller.
	 */
	class MigrationRepository {

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly string $migrationTable = 'quel_migrations',
		) {
		}

		/**
		 * Creates the tracking table if it doesn't already exist. Safe to
		 * call on every run — no-ops once the table is there.
		 * @return void
		 */
		public function ensureTableExists(): void {
			if (in_array($this->migrationTable, $this->entityManager->getConnection()->getTables(), true)) {
				return;
			}

			$this->entityManager->executeQuery(
				"create {$this->migrationTable} (" .
				"version = biginteger, " .
				"migration_name = string(255), " .
				"executed_at = datetime, " .
				"primary key (version))"
			);
		}

		/**
		 * Returns every applied migration's version, ascending.
		 * @return int[]
		 */
		public function getAppliedVersions(): array {
			$rows = $this->entityManager->getConnection()->getConnection()
				->selectQuery('version', $this->migrationTable)
				->orderBy('version')
				->execute()
				->fetchAll('assoc');

			return array_map(static fn(array $row): int => (int)$row['version'], $rows);
		}

		/**
		 * Returns every applied migration's version mapped to the timestamp
		 * it was applied at (`Y-m-d H:i:s`), for MigrationRunner::status() —
		 * getAppliedVersions() alone doesn't carry that timestamp.
		 * @return array<int, string>
		 */
		public function getAppliedRecords(): array {
			$rows = $this->entityManager->getConnection()->getConnection()
				->selectQuery(['version', 'executed_at'], $this->migrationTable)
				->orderBy('version')
				->execute()
				->fetchAll('assoc');

			$records = [];

			foreach ($rows as $row) {
				$records[(int)$row['version']] = $row['executed_at'];
			}

			return $records;
		}

		/**
		 * Records a migration as applied.
		 * @param int $version The migration's own <YmdHis> filename timestamp
		 * @param string $name The migration's class name
		 * @return void
		 */
		public function recordApplied(int $version, string $name): void {
			$this->entityManager->getConnection()->getConnection()
				->insertQuery($this->migrationTable, [
					'version'        => $version,
					'migration_name' => $name,
					'executed_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
				])
				->execute();
		}

		/**
		 * Removes a migration's applied record, undoing recordApplied().
		 * @param int $version
		 * @return void
		 */
		public function recordReverted(int $version): void {
			$this->entityManager->getConnection()->getConnection()
				->deleteQuery($this->migrationTable, ['version' => $version])
				->execute();
		}
	}
