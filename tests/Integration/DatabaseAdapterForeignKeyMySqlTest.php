<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * Part 2.1 / plan Testing section — DatabaseAdapter::getForeignKeys() against a
	 * real MySQL server, mirroring DatabaseAdapterForeignKeyTest's SQLite coverage.
	 *
	 * Uses the same TEST_DB_* environment variables as the framework root's
	 * existing MySQL-backed suite (tests/ObjectQuel), pointed at whatever database
	 * that suite already uses — this test only creates/drops its own uniquely
	 * prefixed tables there, it doesn't touch anything else. Skips entirely (does
	 * not fail) when no MySQL server is reachable, since unlike the SQLite suite
	 * this genuinely depends on external infrastructure.
	 */
	class DatabaseAdapterForeignKeyMySqlTest extends TestCase {

		private ?DatabaseAdapter $adapter = null;

		protected function setUp(): void {
			$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
			$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
			$user = getenv('TEST_DB_USER') ?: 'root';
			$pass = getenv('TEST_DB_PASS') ?: '';

			try {
				$connection = new Connection([
					'driver'   => Mysql::class,
					'host'     => $host,
					'port'     => $port,
					'database' => $name,
					'username' => $user,
					'password' => $pass,
					'encoding' => 'utf8mb4',
				]);
				$connection->getDriver()->connect();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			$this->adapter = new DatabaseAdapter($connection);
			$this->dropFixtureTables();
		}

		protected function tearDown(): void {
			if ($this->adapter !== null) {
				$this->dropFixtureTables();
			}
		}

		private function dropFixtureTables(): void {
			$this->adapter->execute('DROP TABLE IF EXISTS oq_fk_test_orders');
			$this->adapter->execute('DROP TABLE IF EXISTS oq_fk_test_customers');
		}

		public function testGetForeignKeysReadsBackAColumnConstraintWithItsRules(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_test_customers (id INT PRIMARY KEY) ENGINE=InnoDB');
			$this->adapter->execute(
				'CREATE TABLE oq_fk_test_orders (' .
				'id INT PRIMARY KEY, ' .
				'customer_id INT NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_test_orders_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES oq_fk_test_customers(id) ON DELETE CASCADE ON UPDATE RESTRICT' .
				') ENGINE=InnoDB'
			);

			$foreignKeys = $this->adapter->getForeignKeys('oq_fk_test_orders');

			self::assertArrayHasKey('fk_oq_fk_test_orders_customer_id', $foreignKeys);

			$fk = $foreignKeys['fk_oq_fk_test_orders_customer_id'];
			self::assertSame(['customer_id'], $fk['columns']);
			self::assertSame('oq_fk_test_customers', $fk['referencedTable']);
			self::assertSame(['id'], $fk['referencedColumns']);
			self::assertSame('CASCADE', $fk['onDelete']);
			self::assertSame('RESTRICT', $fk['onUpdate']);
		}

		public function testGetForeignKeysReturnsEmptyArrayForATableWithNoConstraints(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_test_customers (id INT PRIMARY KEY) ENGINE=InnoDB');

			self::assertSame([], $this->adapter->getForeignKeys('oq_fk_test_customers'));
		}

		public function testConstraintActuallyEnforcesReferentialIntegrityNotJustDeclaresIt(): void {
			$this->adapter->execute('CREATE TABLE oq_fk_test_customers (id INT PRIMARY KEY) ENGINE=InnoDB');
			$this->adapter->execute(
				'CREATE TABLE oq_fk_test_orders (' .
				'id INT PRIMARY KEY, ' .
				'customer_id INT NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_test_orders_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES oq_fk_test_customers(id)' .
				') ENGINE=InnoDB'
			);

			$this->adapter->execute('INSERT INTO oq_fk_test_customers (id) VALUES (1)');

			$ok = $this->adapter->execute('INSERT INTO oq_fk_test_orders (id, customer_id) VALUES (1, 1)');
			self::assertNotNull($ok);

			$orphan = $this->adapter->execute('INSERT INTO oq_fk_test_orders (id, customer_id) VALUES (2, 999)');
			self::assertNull($orphan);
			self::assertStringContainsString('FOREIGN KEY', (string)$this->adapter->getLastErrorMessage());
		}

		public function testDeletingAReferencedParentIsRejectedWhenNoCascadeRuleExists(): void {
			// The "both raw-SQL DELETE and the DB constraint are real" half of 1.5's
			// distinct-guarantees test — here as a rejection case (default RESTRICT/
			// NO ACTION-equivalent behavior); the CASCADE-succeeds half is already
			// covered by the constraint-creation test above declaring ON DELETE CASCADE.
			$this->adapter->execute('CREATE TABLE oq_fk_test_customers (id INT PRIMARY KEY) ENGINE=InnoDB');
			$this->adapter->execute(
				'CREATE TABLE oq_fk_test_orders (' .
				'id INT PRIMARY KEY, ' .
				'customer_id INT NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_test_orders_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES oq_fk_test_customers(id) ON DELETE RESTRICT' .
				') ENGINE=InnoDB'
			);

			$this->adapter->execute('INSERT INTO oq_fk_test_customers (id) VALUES (1)');
			$this->adapter->execute('INSERT INTO oq_fk_test_orders (id, customer_id) VALUES (1, 1)');

			$result = $this->adapter->execute('DELETE FROM oq_fk_test_customers WHERE id = 1');
			self::assertNull($result);
			self::assertStringContainsString('FOREIGN KEY', (string)$this->adapter->getLastErrorMessage());
		}
	}
