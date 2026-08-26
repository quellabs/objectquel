<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Part 1.4 — SQLite: PRAGMA foreign_keys=ON is issued on every new connection.
	 * Part 2.1 — DatabaseAdapter::getForeignKeys() (SQLite branch), needed by 1.3's
	 * migration diff. Covered here rather than skipped, since 1.3 depends on it
	 * directly and this is the only engine available in this environment without
	 * a live external database server.
	 */
	class DatabaseAdapterForeignKeyTest extends TestCase {
		use FkTestSupport;

		public function testForeignKeysPragmaIsOnImmediatelyAfterConstruction(): void {
			$adapter = $this->makeSqliteAdapter();

			$row = $adapter->execute('PRAGMA foreign_keys')->fetchAssoc();

			self::assertSame(1, (int)$row['foreign_keys']);
		}

		public function testGetForeignKeysReadsBackAColumnConstraintWithItsRules(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE ON UPDATE RESTRICT' .
				')'
			);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayHasKey('fk_orders_customer_id', $foreignKeys);

			$fk = $foreignKeys['fk_orders_customer_id'];
			self::assertSame(['customer_id'], $fk['columns']);
			self::assertSame('customers', $fk['referencedTable']);
			self::assertSame(['id'], $fk['referencedColumns']);
			self::assertSame('CASCADE', $fk['onDelete']);
			self::assertSame('RESTRICT', $fk['onUpdate']);
		}

		public function testGetForeignKeysReturnsEmptyArrayForATableWithNoConstraints(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE standalone (id INTEGER PRIMARY KEY)');

			self::assertSame([], $adapter->getForeignKeys('standalone'));
		}

		public function testPragmaActuallyEnforcesTheConstraintNotJustDeclaresIt(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id)' .
				')'
			);

			$adapter->execute('INSERT INTO customers (id) VALUES (1)');

			// A row referencing an existing parent succeeds.
			$ok = $adapter->execute('INSERT INTO orders (id, customer_id) VALUES (1, 1)');
			self::assertNotNull($ok);

			// A row referencing a non-existent parent must be rejected by SQLite
			// itself — proving the pragma is not just set but actually enforced.
			$orphan = $adapter->execute('INSERT INTO orders (id, customer_id) VALUES (2, 999)');
			self::assertNull($orphan);
			self::assertStringContainsString('FOREIGN KEY constraint failed', $adapter->getLastErrorMessage());
		}

		/**
		 * Distinct from the insert-rejection test above: this proves ON DELETE
		 * CASCADE actually performs the child deletion, not merely that an
		 * invalid state is rejected. A raw DELETE that goes around the ORM
		 * entirely (as here) still removes the child row — the constraint is
		 * real enforcement, not decorative, and this is what makes
		 * Cascade(strategy="database")'s promise (see the plan's problem
		 * statement) actually true now that a real constraint exists to back it.
		 */
		public function testOnDeleteCascadeActuallyDeletesChildRowsNotJustDeclaresIt(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE' .
				')'
			);

			$adapter->execute('INSERT INTO customers (id) VALUES (1)');
			$adapter->execute('INSERT INTO orders (id, customer_id) VALUES (1, 1)');

			// A raw SQL DELETE against the parent, entirely outside the ORM.
			$result = $adapter->execute('DELETE FROM customers WHERE id = 1');
			self::assertNotNull($result);

			$remaining = $adapter->execute('SELECT id FROM orders WHERE id = 1')->fetchAll('assoc');
			self::assertSame([], $remaining);
		}

		/**
		 * The RESTRICT counterpart: a DELETE against a still-referenced parent is
		 * rejected by the database itself, distinct from the insert-side
		 * rejection above.
		 */
		public function testOnDeleteRestrictActuallyBlocksTheDeleteNotJustTheInsert(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT' .
				')'
			);

			$adapter->execute('INSERT INTO customers (id) VALUES (1)');
			$adapter->execute('INSERT INTO orders (id, customer_id) VALUES (1, 1)');

			$result = $adapter->execute('DELETE FROM customers WHERE id = 1');
			self::assertNull($result);
			self::assertStringContainsString('FOREIGN KEY constraint failed', $adapter->getLastErrorMessage());

			$stillThere = $adapter->execute('SELECT id FROM customers WHERE id = 1')->fetchAll('assoc');
			self::assertCount(1, $stillThere);
		}
	}
