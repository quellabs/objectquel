<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlite;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;
	use Quellabs\ObjectQuel\UnitOfWork;

	/**
	 * Part 1.5 — regression check that UnitOfWork::shouldCascadeRemove()'s decision
	 * is genuinely unchanged by the rest of Part 1: still true for "orm" and
	 * "both", still false for "database". No code change was made to UnitOfWork
	 * itself; this test exists to prove that stays true after 1.1-1.4 landed.
	 *
	 * shouldCascadeRemove() is private, so it's exercised via reflection rather
	 * than through a full cascade-delete integration test.
	 */
	class CascadeStrategyTest extends TestCase {
		use FkTestSupport;

		/**
		 * One shared UnitOfWork for the whole class. SignalHub is a process-wide
		 * singleton that throws on a duplicate signal name (see EntityManager's own
		 * "Single EntityManager for the entire test suite" note in tests/bootstrap.php
		 * for the app-level suite) — constructing a fresh EntityManager per test
		 * method would trip that on the second test.
		 */
		private static ?UnitOfWork $unitOfWork = null;

		private static function unitOfWork(): UnitOfWork {
			if (self::$unitOfWork === null) {
				self::loadFkFixtureEntities();

				$connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);

				$configuration = new Configuration();
				$configuration->setEntityPath(__DIR__ . '/../Fixtures/Entities');
				$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\Entities');
				$configuration->setUseMetadataCache(false);

				self::$unitOfWork = (new EntityManager($configuration, $connection))->getUnitOfWork();
			}

			return self::$unitOfWork;
		}

		private function invokeShouldCascadeRemove(Cascade $cascade): bool {
			$reflection = new \ReflectionMethod(UnitOfWork::class, 'shouldCascadeRemove');
			$reflection->setAccessible(true);

			return $reflection->invoke(self::unitOfWork(), $cascade);
		}

		/**
		 * shouldCascadeRemove() gates on two independent things: the 'remove'
		 * operation must be present, and the strategy must not be "database".
		 * 'operations' => ['remove'] isolates the strategy dimension these tests
		 * care about — without it every case would return false regardless of
		 * strategy, for a reason unrelated to Part 1.
		 */
		private function cascade(string $strategy): Cascade {
			return new Cascade(['strategy' => $strategy, 'operations' => ['remove']]);
		}

		public function testStrategyOrmCascadesInPhp(): void {
			self::assertTrue($this->invokeShouldCascadeRemove($this->cascade('orm')));
		}

		public function testStrategyBothStillCascadesInPhp(): void {
			// "both" is the case Part 1's problem statement calls out specifically:
			// the DB constraint added by 1.3 is a backstop, not a replacement — the
			// ORM-side cascade must still fire exactly as it did before this plan.
			self::assertTrue($this->invokeShouldCascadeRemove($this->cascade('both')));
		}

		public function testStrategyDatabaseDoesNotCascadeInPhp(): void {
			// The ORM stays out of the way and trusts the real constraint (1.3) to
			// perform the delete instead.
			self::assertFalse($this->invokeShouldCascadeRemove($this->cascade('database')));
		}
	}
