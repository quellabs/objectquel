<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Tests\Fixtures\BadCascadeEntities\RelParentWithBadCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelOrderNoCascadeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelProfileUnidirectionalEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities\RelUserEntity;
	use Quellabs\ObjectQuel\Tests\Support\SharedTestEntityManager;

	/**
	 * End-to-end validation of Cascade and ForeignKey/ForeignKeyAction for the
	 * two relationship shapes the earlier FK test suites never actually exercised:
	 *
	 *  - OneToOne (bidirectional owning side)
	 *  - "one-to-many" as this ORM expresses it: ManyToOne (owning, "many" side)
	 *    + InverseOf (collection, "one" side) — there is no standalone OneToMany
	 *    annotation here.
	 *
	 * Prior coverage (CascadeStrategyTest) only ever checked the private
	 * shouldCascadeRemove() decision via reflection, never a real remove()+flush()
	 * through UnitOfWork; and every FK fixture (FkOrderEntity etc.) only ever used
	 * a bare ManyToOne with no InverseOf mirror and no OneToOne at all. This class
	 * closes both gaps with real persist/remove/flush cycles against SQLite.
	 *
	 * Uses its own isolated fixture directory (tests/Fixtures/RelationshipEntities)
	 * rather than the shared tests/Fixtures/Entities used by the metadata-only FK
	 * suites, because remove()+flush() triggers
	 * EntityStore::getOrderedDependentEntities(), which eagerly builds metadata
	 * for every entity in the configured entity_path — including fixtures like
	 * FkOrderActionNoFkEntity that are deliberately invalid for other tests'
	 * purposes. Confirmed by trying the shared directory first: it broke on
	 * contact with that fixture.
	 *
	 * The EntityManager itself comes from SharedTestEntityManager — a genuine
	 * process-wide singleton, not just per-class — because UnitOfWork registers
	 * a standalone 'orm.prePersist' signal on the process-wide SignalHub with no
	 * "already registered" guard, so a second EntityManager anywhere else in the
	 * same PHPUnit process throws. This class's own tables are created
	 * idempotently in setUp() (CREATE TABLE IF NOT EXISTS) since another test
	 * class may have claimed the shared instance first, and table state between
	 * tests is reset via DELETE FROM.
	 */
	class RelationshipCascadeForeignKeyTest extends TestCase {

		private static function em(): EntityManager {
			return SharedTestEntityManager::get();
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();

			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_customers (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_orders_cascade (id INTEGER PRIMARY KEY, customer_id INTEGER)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_orders_no_cascade (id INTEGER PRIMARY KEY, customer_id INTEGER)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_users (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles (id INTEGER PRIMARY KEY, user_id INTEGER)');
			$adapter->execute('CREATE TABLE IF NOT EXISTS rel_profiles_unidirectional (id INTEGER PRIMARY KEY, user_id INTEGER)');

			foreach ([
				'rel_orders_cascade', 'rel_orders_no_cascade',
				'rel_profiles', 'rel_profiles_unidirectional',
				'rel_customers', 'rel_users',
			] as $table) {
				$adapter->execute("DELETE FROM {$table}");
			}

			self::em()->getUnitOfWork()->clear();
		}

		// -------------------------------------------------------------------------
		// ManyToOne + InverseOf ("one-to-many") — Cascade(remove), end to end
		// -------------------------------------------------------------------------

		public function testCascadeRemovePresentDeletesOrdersWhenCustomerIsRemoved(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$em->persist($customer);
			$em->flush();

			$order = new RelOrderCascadeEntity();
			$order->customer = $customer;
			$em->persist($order);
			$em->flush();

			$em->remove($customer);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_orders_cascade')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		public function testCascadeRemoveAbsentLeavesOrdersInPlaceWhenCustomerIsRemoved(): void {
			$em = self::em();

			$customer = new RelCustomerEntity();
			$em->persist($customer);
			$em->flush();

			$order = new RelOrderNoCascadeEntity();
			$order->customer = $customer;
			$em->persist($order);
			$em->flush();

			$em->remove($customer);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_orders_no_cascade')->fetchAll('assoc');
			self::assertCount(1, $remaining);
		}

		// -------------------------------------------------------------------------
		// OneToOne (bidirectional owning side) — Cascade(remove), end to end
		// -------------------------------------------------------------------------

		public function testCascadeRemoveWorksForBidirectionalOneToOne(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$em->persist($user);
			$em->flush();

			$profile = new RelProfileEntity();
			$profile->user = $user;
			$em->persist($profile);
			$em->flush();

			$em->remove($user);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_profiles')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		/**
		 * Regression check for a real bug this validation pass found and fixed:
		 * UnitOfWork::handleDependentEntityClass() used to filter OneToOne
		 * dependents down to only those with a non-empty 'referencedColumn'
		 * before even checking their Cascade annotation — silently skipping
		 * cascade-remove for a unidirectional OneToOne that explicitly declares
		 * Cascade(remove). 'referencedColumn' only affects bidirectional setter
		 * sync codegen; it has nothing to do with whether cascade-remove should
		 * run, exactly as ManyToOne (which has no such filter) already proves.
		 */
		public function testCascadeRemoveWorksForUnidirectionalOneToOneToo(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$em->persist($user);
			$em->flush();

			$profile = new RelProfileUnidirectionalEntity();
			$profile->user = $user;
			$em->persist($profile);
			$em->flush();

			$em->remove($user);
			$em->flush();

			$remaining = $em->getConnection()->execute('SELECT id FROM rel_profiles_unidirectional')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		// -------------------------------------------------------------------------
		// OneToOne — Cascade(persist), end to end
		// -------------------------------------------------------------------------

		public function testCascadePersistAutoSavesTheRelatedEntityOnAOneToOneOwningSide(): void {
			$em = self::em();

			$user = new RelUserEntity();
			$profile = new RelProfileEntity();
			$profile->user = $user;

			// Only the owning side is persisted directly — Cascade(persist) on
			// RelProfileEntity::$user is what's expected to pick up the unsaved
			// RelUserEntity and insert it too.
			$em->persist($profile);
			$em->flush();

			self::assertNotNull($user->getId());

			$rows = $em->getConnection()->execute('SELECT id FROM rel_users')->fetchAll('assoc');
			self::assertCount(1, $rows);
		}

		// -------------------------------------------------------------------------
		// ForeignKey/ForeignKeyAction — metadata support for OneToOne
		// -------------------------------------------------------------------------

		public function testForeignKeyAndForeignKeyActionResolveForAOneToOneOwningSide(): void {
			$em = self::em();
			$comparator = new ForeignKeyComparator($em->getConnection(), $em->getEntityStore());

			$result = $comparator->getEntityForeignKeys(RelProfileEntity::class);

			self::assertArrayHasKey('fk_rel_profiles_user_id', $result);
			self::assertSame(['user_id'], $result['fk_rel_profiles_user_id']['columns']);
			self::assertSame('rel_users', $result['fk_rel_profiles_user_id']['referencedTable']);
			self::assertSame('CASCADE', $result['fk_rel_profiles_user_id']['onDelete']);
			self::assertSame('NO ACTION', $result['fk_rel_profiles_user_id']['onUpdate']);
		}

		// -------------------------------------------------------------------------
		// By design, not a bug: InverseOf is a hydration instruction, not a
		// relation — it never owns the FK, so Cascade (which governs walking and
		// persisting/removing a related object the ORM owns) has nothing to do
		// on it. Declaring Cascade there is rejected at build time. This uses a
		// THIRD isolated EntityStore (not EntityManager, so it never touches
		// SignalHub) pointed at yet another directory, since loading this pair
		// is expected to throw.
		// -------------------------------------------------------------------------

		public function testCascadeOnAnInverseOfCollectionIsRejectedByDesign(): void {
			$badFixturesDir = __DIR__ . '/../Fixtures/BadCascadeEntities';

			foreach (glob($badFixturesDir . '/*.php') ?: [] as $file) {
				require_once $file;
			}

			$configuration = new Configuration();
			$configuration->setEntityPath($badFixturesDir);
			$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\BadCascadeEntities');
			$configuration->setUseMetadataCache(false);

			$entityStore = new EntityStore($configuration);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/carries an .@Orm\\\\Cascade. annotation but no .@Orm\\\\ManyToOne. or .@Orm\\\\OneToOne./');

			$entityStore->getMetadata(RelParentWithBadCascadeEntity::class);
		}
	}
