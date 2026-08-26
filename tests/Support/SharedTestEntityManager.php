<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlite;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Provides the ONE EntityManager shared across the entire test process.
	 *
	 * UnitOfWork::__construct() registers 'orm.prePersist' (and its siblings) on
	 * the process-wide SignalHub with no "already registered" guard — unlike
	 * EntityManager's own debugQuerySignal, which does check first. A second
	 * EntityManager constructed anywhere in the same PHPUnit process throws
	 * "Standalone signal 'orm.prePersist' is already registered". So every test
	 * class in this package that needs a real EntityManager (not just a bare
	 * EntityStore/metadata build) must share this single instance rather than
	 * building its own — a plain class with a static instance, not a trait,
	 * since a trait's static property is per-class, not process-wide, and
	 * wouldn't actually prevent the double construction.
	 *
	 * Scoped to tests/Fixtures/RelationshipEntities, not the shared
	 * tests/Fixtures/Entities used by the metadata-only FK test suites — those
	 * deliberately contain invalid fixtures (e.g. FkOrderActionNoFkEntity) that
	 * explode the moment EntityStore::getOrderedDependentEntities() eagerly
	 * builds metadata for the whole registry, which any remove()+flush() call
	 * triggers.
	 */
	final class SharedTestEntityManager {

		private static ?EntityManager $instance = null;

		public static function get(): EntityManager {
			if (self::$instance === null) {
				$fixturesDir = __DIR__ . '/../Fixtures/RelationshipEntities';

				foreach (glob($fixturesDir . '/*.php') ?: [] as $file) {
					require_once $file;
				}

				$connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);

				$configuration = new Configuration();
				$configuration->setEntityPath($fixturesDir);
				$configuration->setEntityNameSpace('Quellabs\\ObjectQuel\\Tests\\Fixtures\\RelationshipEntities');
				$configuration->setUseMetadataCache(false);

				self::$instance = new EntityManager($configuration, $connection);
			}

			return self::$instance;
		}
	}
