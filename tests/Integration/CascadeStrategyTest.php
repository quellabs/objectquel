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
	 * Cascade no longer carries a "strategy" (orm/database/both) — it's purely
	 * PHP-side behavior now, fully independent of any database foreign key
	 * constraint (see @Orm\ForeignKey/@Orm\ForeignKeyAction for that). This is a
	 * regression check that UnitOfWork::shouldCascadeRemove()'s decision is
	 * exactly what Cascade::getOperations() says and nothing more: true when
	 * "remove" is present, false otherwise.
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

		public function testRemoveOperationPresentCascadesInPhp(): void {
			self::assertTrue($this->invokeShouldCascadeRemove(new Cascade(['operations' => ['remove']])));
		}

		public function testRemoveOperationAbsentDoesNotCascadeInPhp(): void {
			self::assertFalse($this->invokeShouldCascadeRemove(new Cascade(['operations' => ['persist']])));
		}

		public function testNoOperationsAtAllDoesNotCascadeInPhp(): void {
			self::assertFalse($this->invokeShouldCascadeRemove(new Cascade([])));
		}
	}
