<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityFromTableCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * Part 2.3-2.5 against a real MySQL server, mirroring
	 * MakeEntityFromTableForeignKeyTest's SQLite coverage. The command's
	 * detection/emission logic itself is engine-agnostic (it only consumes the
	 * ColumnDefinition/ForeignKeyDefinition array shapes DatabaseAdapter already
	 * normalizes per engine), but had only ever been exercised against SQLite —
	 * this closes that gap the same way DatabaseAdapterForeignKeyMySqlTest closed
	 * it for DatabaseAdapter::getForeignKeys() itself.
	 *
	 * Uses the same TEST_DB_* environment variables as the framework root's
	 * existing MySQL-backed suite. Only creates/drops its own uniquely prefixed
	 * tables. Skips entirely (does not fail) when no MySQL server is reachable.
	 */
	class MakeEntityFromTableForeignKeyMySqlTest extends TestCase {

		private ?ServiceProvider $provider = null;

		protected function setUp(): void {
			$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
			$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
			$user = getenv('TEST_DB_USER') ?: 'root';
			$pass = getenv('TEST_DB_PASS') ?: '';

			$provider = new ServiceProvider();
			$provider->setConfig([
				'driver'                => 'mysql',
				'host'                  => $host,
				'port'                  => $port,
				'database'              => $name,
				'username'              => $user,
				'password'              => $pass,
				'encoding'              => 'utf8mb4',
				'entity_namespace'      => 'App\\Entities',
				'entity_path'           => sys_get_temp_dir(),
				'generate_foreign_keys' => true,
			]);

			try {
				$provider->getDatabaseAdapter()->getTables();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			$this->provider = $provider;
			$this->dropFixtureTables();
		}

		protected function tearDown(): void {
			if ($this->provider !== null) {
				$this->dropFixtureTables();
			}
		}

		private function dropFixtureTables(): void {
			$adapter = $this->provider->getDatabaseAdapter();
			$adapter->execute('DROP TABLE IF EXISTS oq_mk_orders');
			$adapter->execute('DROP TABLE IF EXISTS oq_mk_customers');
		}

		/**
		 * Reproduces the relevant slice of execute()'s assembly, via reflection
		 * into the private helpers — see MakeEntityFromTableForeignKeyTest.
		 */
		private function generateEntityCode(string $table): string {
			$output = new ConsoleOutput(fopen('php://memory', 'w+'));
			$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
			$command = new MakeEntityFromTableCommand($input, $output, $this->provider);

			$reflection = new \ReflectionClass($command);

			$invoke = function (string $method, array $args = []) use ($reflection, $command) {
				$reflectionMethod = $reflection->getMethod($method);
				$reflectionMethod->setAccessible(true);
				return $reflectionMethod->invoke($command, ...$args);
			};

			$tableDescription = $this->provider->getDatabaseAdapter()->getColumns($table);
			$foreignKeys = $invoke('getTableForeignKeys', [$table]);
			$camelCase = $this->camelCase($table);

			$code = "<?php\n";
			$code .= $invoke('generateNamespace');
			$code .= $invoke('generateImports');
			$code .= $invoke('generateClassDocBlock', [$table, $camelCase]);
			$code .= "    class {$camelCase}Entity {\n";
			$code .= $invoke('generateMemberVariables', [$tableDescription, $foreignKeys]);
			$code .= $invoke('generateGettersAndSetters', [$tableDescription]);
			$code .= "    }\n";

			return $code;
		}

		private function camelCase(string $input): string {
			return implode('', array_map('ucfirst', explode('_', $input)));
		}

		public function testGenerateForeignKeysOnEmitsScalarPropertyPlusMatchingAnnotation(): void {
			$adapter = $this->provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE oq_mk_customers (id INT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute(
				'CREATE TABLE oq_mk_orders (' .
				'id INT PRIMARY KEY, ' .
				'customer_id INT NOT NULL, ' .
				'CONSTRAINT fk_oq_mk_orders_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES oq_mk_customers(id) ON DELETE CASCADE ON UPDATE RESTRICT' .
				') ENGINE=InnoDB'
			);

			$code = $this->generateEntityCode('oq_mk_orders');

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\OqMkCustomersEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")',
				$code
			);
			self::assertStringContainsString('protected int $customerId;', $code);
			self::assertStringNotContainsString('@Orm\ManyToOne', $code);
			self::assertStringNotContainsString('@Orm\Cascade', $code);
		}

		public function testConstraintMatchingSafeDefaultsExactlyEmitsNoForeignKeyActionAtAll(): void {
			$adapter = $this->provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE oq_mk_customers (id INT PRIMARY KEY) ENGINE=InnoDB');
			$adapter->execute(
				'CREATE TABLE oq_mk_orders (' .
				'id INT PRIMARY KEY, ' .
				'customer_id INT NOT NULL, ' .
				'CONSTRAINT fk_oq_mk_orders_customer_id FOREIGN KEY (customer_id) ' .
				'REFERENCES oq_mk_customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				') ENGINE=InnoDB'
			);

			$code = $this->generateEntityCode('oq_mk_orders');

			self::assertStringContainsString('@Orm\ForeignKey(', $code);
			self::assertStringNotContainsString('@Orm\ForeignKeyAction', $code);
		}
	}
