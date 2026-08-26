<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhinxMigrationBuilder;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderRemovedEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Part 1.3 — MakeMigrationsCommand's underlying machinery: ForeignKeyComparator
	 * (the FK-vs-schema diff) and PhinxMigrationBuilder (code generation + the
	 * create-everything-then-addForeignKey ordering).
	 *
	 * Deliberately scoped to just the foreign-key-specific diff/emission logic —
	 * column-level diffing (SchemaComparator) predates this plan and isn't part of
	 * it. Each scenario below hand-assembles the EntityChangeSet PhinxMigrationBuilder
	 * expects, using real metadata (EntityStore) and a real foreign-key diff
	 * (ForeignKeyComparator against a live in-memory SQLite table) rather than
	 * mocks, so this exercises the actual Part 1 code paths end to end.
	 */
	class ForeignKeyMigrationTest extends TestCase {
		use FkTestSupport;

		private DatabaseAdapter $adapter;
		private EntityStore $entityStore;
		private ForeignKeyComparator $comparator;
		private PhinxMigrationBuilder $builder;

		protected function setUp(): void {
			$this->adapter = $this->makeSqliteAdapter();
			$this->entityStore = $this->makeFkEntityStore();
			$this->comparator = new ForeignKeyComparator($this->adapter, $this->entityStore);
			$this->builder = new PhinxMigrationBuilder($this->adapter, sys_get_temp_dir());
		}

		/**
		 * Invokes PhinxMigrationBuilder's private buildMigrationContent() so the
		 * generated migration source can be asserted on directly, without writing
		 * a file to disk.
		 * @param array<string, array<string, mixed>> $allChanges
		 */
		private function buildMigrationContent(array $allChanges): string {
			$method = new \ReflectionMethod(PhinxMigrationBuilder::class, 'buildMigrationContent');
			$method->setAccessible(true);
			return $method->invoke($this->builder, 'TestMigration', $allChanges);
		}

		private function emptyEntityChangeSet(): array {
			return [
				'added'    => [],
				'modified' => [],
				'deleted'  => [],
				'indexes'  => ['added' => [], 'modified' => [], 'deleted' => []],
			];
		}

		// -------------------------------------------------------------------------
		// Added
		// -------------------------------------------------------------------------

		public function testNewForeignKeyRelationEmitsAddForeignKeyWithTheRightConfig(): void {
			// customers table exists but has no constraint yet — orders table
			// exists with a bare customer_id column, matching what a project would
			// have before adopting @Orm\ForeignKey on an already-existing table.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute('CREATE TABLE fk_orders_scalar (id INTEGER PRIMARY KEY, customer_id INTEGER)');

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarEntity::class);

			self::assertArrayHasKey('fk_fk_orders_scalar_customer_id', $fkDiff['added']);
			self::assertSame([], $fkDiff['modified']);
			self::assertSame([], $fkDiff['deleted']);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_scalar' => $changes]);

			self::assertStringContainsString(
				"->addForeignKey(['customer_id'], 'fk_customers', ['id'], " .
				"['delete' => 'RESTRICT', 'update' => 'NO ACTION', 'constraint' => 'fk_fk_orders_scalar_customer_id'])",
				$content
			);
			// down() must undo it with the same constraint name.
			self::assertStringContainsString(
				"->dropForeignKey(['customer_id'], 'fk_fk_orders_scalar_customer_id')",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Deleted
		// -------------------------------------------------------------------------

		public function testRemovedForeignKeyAnnotationEmitsADrop(): void {
			// The live table already has a real constraint (as if @Orm\ForeignKey
			// were removed from an entity that used to declare it); the entity
			// itself declares none.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_removed (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'FOREIGN KEY (customer_id) REFERENCES fk_customers(id) ON DELETE RESTRICT' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderRemovedEntity::class);

			self::assertSame([], $fkDiff['added']);
			self::assertSame([], $fkDiff['modified']);
			self::assertArrayHasKey('fk_fk_orders_removed_customer_id', $fkDiff['deleted']);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_removed' => $changes]);

			self::assertStringContainsString(
				"->dropForeignKey(['customer_id'], 'fk_fk_orders_removed_customer_id')",
				$content
			);
			// down() must restore it.
			self::assertStringContainsString(
				"->addForeignKey(['customer_id'], 'fk_customers', ['id'], " .
				"['delete' => 'RESTRICT', 'update' => 'NO ACTION', 'constraint' => 'fk_fk_orders_removed_customer_id'])",
				$content
			);
		}

		// -------------------------------------------------------------------------
		// Modified — the one bucket no prior test ever populated
		// -------------------------------------------------------------------------

		public function testModifiedForeignKeyActionEmitsADropAndRecreateWithTheNewRule(): void {
			// The live constraint already exists under the exact name this
			// generator would produce, but with the *old* rule (RESTRICT/NO
			// ACTION) — as if @Orm\ForeignKeyAction was just added/changed to
			// CASCADE/RESTRICT on FkOrderScalarActionEntity since the last run.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_scalar_action (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'CONSTRAINT fk_fk_orders_scalar_action_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES fk_customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarActionEntity::class);

			self::assertSame([], $fkDiff['added']);
			self::assertSame([], $fkDiff['deleted']);
			self::assertArrayHasKey('fk_fk_orders_scalar_action_customer_id', $fkDiff['modified']);

			$modified = $fkDiff['modified']['fk_fk_orders_scalar_action_customer_id'];
			self::assertSame('RESTRICT', $modified['database']['onDelete']);
			self::assertSame('NO ACTION', $modified['database']['onUpdate']);
			self::assertSame('CASCADE', $modified['entity']['onDelete']);
			self::assertSame('RESTRICT', $modified['entity']['onUpdate']);

			$changes = $this->emptyEntityChangeSet();
			$changes['foreignKeys'] = $fkDiff;

			$content = $this->buildMigrationContent(['fk_orders_scalar_action' => $changes]);

			// up() drops the old constraint (by name) and adds the new one with
			// the entity's rule.
			self::assertStringContainsString(
				"->dropForeignKey(['customer_id'], 'fk_fk_orders_scalar_action_customer_id')",
				$content
			);
			self::assertStringContainsString(
				"->addForeignKey(['customer_id'], 'fk_customers', ['id'], " .
				"['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'fk_fk_orders_scalar_action_customer_id'])",
				$content
			);

			// down() must restore the database's original rule, not just repeat
			// the entity's rule under a different name — invertForeignKeyModifications()
			// swaps entity/database, so this is the regression check that the swap
			// actually happened rather than being a no-op.
			$upPos = strpos($content, 'public function up');
			$downPos = strpos($content, 'public function down');
			self::assertNotFalse($upPos);
			self::assertNotFalse($downPos);

			$downBody = substr($content, $downPos);
			self::assertStringContainsString(
				"->addForeignKey(['customer_id'], 'fk_customers', ['id'], " .
				"['delete' => 'RESTRICT', 'update' => 'NO ACTION', 'constraint' => 'fk_fk_orders_scalar_action_customer_id'])",
				$downBody
			);
		}

		// -------------------------------------------------------------------------
		// Cascade is fully independent of the generated constraint
		// -------------------------------------------------------------------------

		public function testCascadePresentAlongsideForeignKeyDoesNotAffectTheGeneratedConstraint(): void {
			// FkOrderEntity stacks ManyToOne + Cascade + ForeignKey + ForeignKeyAction
			// on the same relation property. This is pure metadata resolution — no
			// live table needs to exist — and proves the generated constraint comes
			// entirely from ForeignKey/ForeignKeyAction, exactly as it would if
			// Cascade weren't declared at all (compare FkOrderScalarActionEntity's
			// ForeignKeyAction(onDelete="CASCADE") above, which has no Cascade,
			// ManyToOne, or object relation anywhere).
			$definitions = $this->comparator->getEntityForeignKeys(FkOrderEntity::class);

			self::assertArrayHasKey('fk_fk_orders_customer_id', $definitions);

			$definition = $definitions['fk_fk_orders_customer_id'];
			self::assertSame(['customer_id'], $definition['columns']);
			self::assertSame('fk_customers', $definition['referencedTable']);
			self::assertSame(['id'], $definition['referencedColumns']);
			self::assertSame('CASCADE', $definition['onDelete']);
			// onUpdate was never declared on FkOrderEntity's ForeignKeyAction —
			// the plain annotation default, unaffected by Cascade being present.
			self::assertSame('NO ACTION', $definition['onUpdate']);
		}

		// -------------------------------------------------------------------------
		// Determinism
		// -------------------------------------------------------------------------

		public function testUnchangedForeignKeyProducesAnEmptyDiffOnASecondRun(): void {
			// The live schema already matches exactly what FkOrderScalarEntity
			// declares — including the deterministic constraint name and the rules
			// this same generator would have produced. Re-running the diff must be
			// a no-op, or every regeneration would spuriously drop-and-recreate the
			// constraint.
			$this->adapter->execute('CREATE TABLE fk_customers (id INTEGER PRIMARY KEY)');
			$this->adapter->execute(
				'CREATE TABLE fk_orders_scalar (' .
				'id INTEGER PRIMARY KEY, customer_id INTEGER, ' .
				'FOREIGN KEY (customer_id) REFERENCES fk_customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				')'
			);

			$fkDiff = $this->comparator->compareForeignKeys(FkOrderScalarEntity::class);

			self::assertSame(['added' => [], 'modified' => [], 'deleted' => []], $fkDiff);
		}

		// -------------------------------------------------------------------------
		// Ordering
		// -------------------------------------------------------------------------

		public function testTwoNewCrossReferencingTablesEmitBothCreatesBeforeEitherAddForeignKey(): void {
			// Neither table exists yet in this fresh in-memory database.
			$customerMetadata = $this->entityStore->getMetadata(FkCustomerEntity::class);
			$orderMetadata = $this->entityStore->getMetadata(FkOrderScalarEntity::class);

			$allChanges = [
				'fk_customers'     => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $customerMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => [], 'modified' => [], 'deleted' => []],
				]),
				'fk_orders_scalar' => array_merge($this->emptyEntityChangeSet(), [
					'table_not_exists' => true,
					'added'             => $orderMetadata->getColumnDefinitionsForSchema(),
					'foreignKeys'       => ['added' => $this->comparator->getEntityForeignKeys(FkOrderScalarEntity::class), 'modified' => [], 'deleted' => []],
				]),
			];

			$content = $this->buildMigrationContent($allChanges);

			$customersCreatePos = strpos($content, "\$this->table('fk_customers'");
			$ordersCreatePos = strpos($content, "\$this->table('fk_orders_scalar', ['id' => false");
			$addForeignKeyPos = strpos($content, '->addForeignKey(');

			self::assertNotFalse($customersCreatePos);
			self::assertNotFalse($ordersCreatePos);
			self::assertNotFalse($addForeignKeyPos);

			// Both create() calls appear before the addForeignKey() call — no
			// dependency-ordering algorithm needed between the two tables
			// themselves, since neither create() carries an inline FK.
			self::assertLessThan($addForeignKeyPos, $customersCreatePos);
			self::assertLessThan($addForeignKeyPos, $ordersCreatePos);
		}
	}
