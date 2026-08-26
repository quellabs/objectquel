<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Tests\Support\SharedTestEntityManager;
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
	 * than through a full cascade-delete integration test — see
	 * RelationshipCascadeForeignKeyTest for that end-to-end coverage.
	 *
	 * The UnitOfWork comes from SharedTestEntityManager rather than a private
	 * EntityManager of this class's own: SignalHub is a process-wide singleton
	 * that throws on a duplicate 'orm.prePersist' registration, so only one
	 * EntityManager can ever exist per PHPUnit process — see that class's
	 * docblock for the full explanation.
	 */
	class CascadeStrategyTest extends TestCase {

		private static function unitOfWork(): UnitOfWork {
			return SharedTestEntityManager::get()->getUnitOfWork();
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
