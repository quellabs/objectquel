<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Drives migration discovery and execution — the replacement for
	 * Phinx's Manager inside QuelMigrateCommand.
	 *
	 * Each migration's up()/down() runs in its own transaction when
	 * PlatformCapabilitiesInterface::supportsTransactionalDDL() is true;
	 * otherwise best-effort, since MySQL/MariaDB DDL auto-commits per
	 * statement regardless. Either way, a failing migration stops the run —
	 * later pending/applied migrations in the same call are never touched.
	 */
	class MigrationRunner {

		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly PlatformCapabilitiesInterface $platform,
			private readonly MigrationRepository $repository,
			private readonly MigrationLocator $locator,
		) {
		}

		/**
		 * Every located migration not yet recorded as applied, ascending by
		 * version.
		 * @return list<LocatedMigration>
		 */
		public function getPending(): array {
			$this->repository->ensureTableExists();

			$applied = array_flip($this->repository->getAppliedVersions());

			return array_values(array_filter(
				$this->locator->locate(),
				static fn(LocatedMigration $migration) => !isset($applied[$migration->version])
			));
		}

		/**
		 * Applies every pending migration, ascending by version. With
		 * $target given, stops after applying the migration whose version
		 * equals $target (inclusive) — later pending migrations are left
		 * untouched.
		 * @param int|null $target
		 * @return void
		 * @throws \Throwable Propagated from a failing migration's up(), after rollback where the platform supports it
		 */
		public function migrate(?int $target = null): void {
			foreach ($this->getPending() as $migration) {
				if ($target !== null && $migration->version > $target) {
					break;
				}

				$this->runTransactionally(static fn() => $migration->migration->up());
				$this->repository->recordApplied($migration->version, $migration->name);
			}
		}

		/**
		 * Reverts applied migrations, most-recent-first. With $target
		 * given, reverts every applied migration whose version is greater
		 * than $target (exclusive — $target itself stays applied);
		 * otherwise reverts the $steps most recently applied migrations.
		 * @param int|null $target
		 * @param int $steps Only consulted when $target is null
		 * @return void
		 * @throws \Throwable Propagated from a failing migration's down(), after rollback where the platform supports it
		 */
		public function rollback(?int $target = null, int $steps = 1): void {
			foreach ($this->getRollbackCandidates($target, $steps) as $migration) {
				$this->runTransactionally(static fn() => $migration->migration->down());
				$this->repository->recordReverted($migration->version);
			}
		}

		/**
		 * The applied migrations rollback($target, $steps) would revert,
		 * without reverting them — used both by rollback() itself and by a
		 * caller previewing what a rollback would do (e.g. --dry-run) without
		 * invoking any migration's down().
		 * @param int|null $target
		 * @param int $steps Only consulted when $target is null
		 * @return list<LocatedMigration> Most-recently-applied first
		 */
		public function getRollbackCandidates(?int $target = null, int $steps = 1): array {
			$this->repository->ensureTableExists();

			$appliedVersions = array_flip($this->repository->getAppliedVersions());

			$applied = array_values(array_filter(
				$this->locator->locate(),
				static fn(LocatedMigration $migration) => isset($appliedVersions[$migration->version])
			));

			usort($applied, static fn(LocatedMigration $a, LocatedMigration $b) => $b->version <=> $a->version);

			return $target !== null
				? array_values(array_filter($applied, static fn(LocatedMigration $migration) => $migration->version > $target))
				: array_slice($applied, 0, $steps);
		}

		/**
		 * Every located migration, ascending by version, paired with
		 * whether (and when) it's been applied.
		 * @return list<array{version: int, name: string, applied_at: string|null}>
		 */
		public function status(): array {
			$this->repository->ensureTableExists();

			$appliedRecords = $this->repository->getAppliedRecords();

			return array_map(
				static fn(LocatedMigration $migration) => [
					'version'    => $migration->version,
					'name'       => $migration->name,
					'applied_at' => $appliedRecords[$migration->version] ?? null,
				],
				$this->locator->locate()
			);
		}

		/**
		 * Runs $callback wrapped in a transaction when the platform
		 * supports transactional DDL, rolling back and rethrowing on
		 * failure; otherwise runs it directly (MySQL/MariaDB DDL
		 * auto-commits per statement regardless of an open transaction).
		 * @param callable(): void $callback
		 * @return void
		 * @throws \Throwable
		 */
		private function runTransactionally(callable $callback): void {
			if (!$this->platform->supportsTransactionalDDL()) {
				$callback();
				return;
			}

			$connection = $this->entityManager->getConnection();
			$connection->beginTrans();

			try {
				$callback();
			} catch (\Throwable $e) {
				$connection->rollbackTrans();
				throw $e;
			}

			$connection->commitTrans();
		}
	}
