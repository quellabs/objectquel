<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityFromTableCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * Part 2.3-2.5, revised — MakeEntityFromTableCommand: detecting a real database
	 * foreign key constraint and annotating the generated entity with a matching
	 * @Orm\ForeignKey (structure) plus, when the detected rule isn't the safe
	 * default, a separate @Orm\ForeignKeyAction (behavior) — gated by the
	 * generate_foreign_keys config key (2.2).
	 *
	 * Exercises the command's private code-generation helpers directly via
	 * reflection (mirroring ForeignKeyMigrationTest's approach) rather than
	 * driving execute(), since execute() prompts interactively for a table name.
	 *
	 * Uses a file-backed SQLite database rather than ':memory:'. DatabaseAdapter
	 * reads column definitions through a separate Phinx-adapter PDO connection
	 * (getPhinxAdapter(), a second connection built straight from the CakePHP
	 * connection's config — see DatabaseAdapter::getPhinxAdapter()) rather than
	 * the primary CakePHP connection used for execute(). Two independent
	 * ':memory:' connections are two independent empty databases, so a table
	 * created via execute() would be invisible to getColumns(). A shared temp
	 * file sidesteps that without depending on SQLite's less portable
	 * shared-cache URI syntax.
	 */
	class MakeEntityFromTableForeignKeyTest extends TestCase {

		private string $dbFile;

		protected function setUp(): void {
			$this->dbFile = sys_get_temp_dir() . '/oq_fk_test_' . uniqid() . '.sqlite';
		}

		protected function tearDown(): void {
			// Best-effort cleanup: on Windows the file can still be briefly held
			// open by a PDO connection (CakePHP's and Phinx's separate one, see
			// class docblock) that hasn't been garbage-collected yet.
			if (is_file($this->dbFile)) {
				@unlink($this->dbFile);
			}
		}

		/**
		 * @return array{0: MakeEntityFromTableCommand, 1: ServiceProvider}
		 */
		private function makeCommand(bool $generateForeignKeys): array {
			$provider = new ServiceProvider();
			$provider->setConfig([
				'driver'                => 'sqlite',
				'database'              => $this->dbFile,
				'entity_namespace'      => 'App\\Entities',
				'entity_path'           => sys_get_temp_dir(),
				'generate_foreign_keys' => $generateForeignKeys,
			]);

			$output = new ConsoleOutput(fopen('php://memory', 'w+'));
			$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
			$command = new MakeEntityFromTableCommand($input, $output, $provider);

			return [$command, $provider];
		}

		/**
		 * Reproduces the relevant slice of execute()'s assembly (namespace/imports/
		 * docblock/member variables) for one table, via reflection into the
		 * private helpers, without needing to drive the interactive prompt.
		 */
		private function generateEntityCode(MakeEntityFromTableCommand $command, ServiceProvider $provider, string $table, bool $generateForeignKeys): string {
			$reflection = new \ReflectionClass($command);

			$invoke = function (string $method, array $args = []) use ($reflection, $command) {
				$reflectionMethod = $reflection->getMethod($method);
				$reflectionMethod->setAccessible(true);
				return $reflectionMethod->invoke($command, ...$args);
			};

			$tableDescription = $provider->getDatabaseAdapter()->getColumns($table);

			$foreignKeys = $generateForeignKeys
				? $invoke('getTableForeignKeys', [$table])
				: [];

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

		// -------------------------------------------------------------------------
		// Off is byte-identical to today
		// -------------------------------------------------------------------------

		public function testGenerateForeignKeysOffProducesNoForeignKeyAnnotationOrImportEvenWithARealConstraint(): void {
			[$command, $provider] = $this->makeCommand(false);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', false);

			self::assertStringNotContainsString('ForeignKey', $code);
			self::assertStringNotContainsString('ForeignKeyAction', $code);
			self::assertStringContainsString('protected int $customerId;', $code);
		}

		// -------------------------------------------------------------------------
		// On: scalar property + matching annotation, not a ManyToOne conversion
		// -------------------------------------------------------------------------

		public function testGenerateForeignKeysOnEmitsScalarPropertyPlusMatchingAnnotation(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE ON UPDATE RESTRICT' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString('use Quellabs\\ObjectQuel\\Annotations\Orm\ForeignKey;', $code);
			self::assertStringContainsString('use Quellabs\\ObjectQuel\\Annotations\Orm\ForeignKeyAction;', $code);
			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\CustomersEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")',
				$code
			);

			// Still a plain scalar property, not converted to an object relation.
			// (The ManyToOne *import* is always emitted regardless — see
			// generateImports() — so the absence check is on actual usage.)
			self::assertStringContainsString('protected int $customerId;', $code);
			self::assertStringContainsString('public function getCustomerId() : int', $code);
			self::assertStringNotContainsString('@Orm\ManyToOne', $code);
			self::assertStringNotContainsString('@Orm\Cascade', $code);
			self::assertStringNotContainsString('CustomerEntity $customer', $code);
		}

		// -------------------------------------------------------------------------
		// Self-referencing FK
		// -------------------------------------------------------------------------

		public function testSelfReferencingForeignKeyResolvesTargetToItsOwnEntityClass(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute(
				'CREATE TABLE employees (' .
				'id INTEGER PRIMARY KEY, ' .
				'manager_id INTEGER, ' .
				'FOREIGN KEY (manager_id) REFERENCES employees(id)' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'employees', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\EmployeesEntity", referencedColumn="id")',
				$code
			);

			// No ON DELETE was declared, so SQLite's real default (NO ACTION, not
			// RESTRICT — see 2.5's round-trip test below) differs from the
			// ForeignKeyAction default (RESTRICT), so it's emitted explicitly to
			// round-trip the real rule rather than silently defaulting.
			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="NO ACTION", onUpdate="NO ACTION")',
				$code
			);
		}

		// -------------------------------------------------------------------------
		// Target entity not generated yet
		// -------------------------------------------------------------------------

		public function testForeignKeyToATableWithNoEntityGeneratedYetStillResolvesATargetClassNameString(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			// 'suppliers' has no corresponding entity file anywhere — the target
			// class name must still resolve as a plain string.
			$adapter->execute('CREATE TABLE suppliers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE products (' .
				'id INTEGER PRIMARY KEY, ' .
				'supplier_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (supplier_id) REFERENCES suppliers(id)' .
				')'
			);

			// No 'SuppliersEntity.php' exists anywhere on disk for this test — the
			// command never checks for one, so this proves the target class name
			// resolves purely from the configured naming convention.
			$code = $this->generateEntityCode($command, $provider, 'products', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\SuppliersEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="NO ACTION", onUpdate="NO ACTION")',
				$code
			);
		}

		// -------------------------------------------------------------------------
		// onDelete round-trips the real rule
		// -------------------------------------------------------------------------

		public function testOnDeleteRoundTripsTheSourceConstraintsActualRuleRatherThanDefaultingToRestrict(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="SET NULL", onUpdate="NO ACTION")',
				$code
			);
			self::assertStringNotContainsString('onDelete="RESTRICT"', $code);
		}

		// -------------------------------------------------------------------------
		// Composite foreign keys aren't representable, so they're skipped
		// -------------------------------------------------------------------------

		public function testCompositeForeignKeyIsSkippedRatherThanEmittingAPartialAnnotation(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE regions (country_code TEXT, region_code TEXT, PRIMARY KEY (country_code, region_code))');
			$adapter->execute(
				'CREATE TABLE stores (' .
				'id INTEGER PRIMARY KEY, ' .
				'country_code TEXT NOT NULL, ' .
				'region_code TEXT NOT NULL, ' .
				'FOREIGN KEY (country_code, region_code) REFERENCES regions(country_code, region_code)' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'stores', true);

			self::assertStringNotContainsString('@Orm\ForeignKey', $code);
			self::assertStringNotContainsString('@Orm\ForeignKeyAction', $code);
		}

		// -------------------------------------------------------------------------
		// A constraint that matches the safe defaults exactly emits no
		// ForeignKeyAction at all — presence means something was actually declared.
		// -------------------------------------------------------------------------

		public function testConstraintMatchingSafeDefaultsExactlyEmitsNoForeignKeyActionAtAll(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				// Explicitly RESTRICT/NO ACTION, not omitted — SQLite's *omitted*
				// default is NO ACTION for onDelete too (see the self-referencing
				// test above), so only an explicit RESTRICT here actually exercises
				// "detected value equals the annotation default".
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT ON UPDATE NO ACTION' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\CustomersEntity", referencedColumn="id")',
				$code
			);
			self::assertStringNotContainsString('@Orm\ForeignKeyAction', $code);
		}

		// -------------------------------------------------------------------------
		// Only onUpdate deviates — ForeignKeyAction still emits both values together
		// -------------------------------------------------------------------------

		public function testOnUpdateOnlyDeviationStillEmitsForeignKeyActionWithBothValues(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT ON UPDATE CASCADE' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString(
				'@Orm\ForeignKeyAction(onDelete="RESTRICT", onUpdate="CASCADE")',
				$code
			);
		}

		// -------------------------------------------------------------------------
		// Multiple FK columns on the same table are each annotated independently
		// -------------------------------------------------------------------------

		public function testMultipleForeignKeysOnDifferentColumnsAreEachAnnotatedIndependently(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
			$adapter->execute('CREATE TABLE warehouses (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_id INTEGER NOT NULL, ' .
				'warehouse_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE, ' .
				'FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\CustomersEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\WarehousesEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString('@Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="NO ACTION")', $code);
			self::assertStringContainsString('@Orm\ForeignKeyAction(onDelete="SET NULL", onUpdate="NO ACTION")', $code);
			self::assertStringContainsString('protected int $customerId;', $code);
			self::assertStringContainsString('protected int $warehouseId;', $code);
		}

		// -------------------------------------------------------------------------
		// A table with no foreign keys at all produces no FK-related output
		// -------------------------------------------------------------------------

		public function testTableWithNoForeignKeysProducesNoForeignKeyOutputEvenWithGenerateForeignKeysOn(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE standalone (id INTEGER PRIMARY KEY, name TEXT)');

			$code = $this->generateEntityCode($command, $provider, 'standalone', true);

			self::assertStringNotContainsString('@Orm\ForeignKey', $code);
			self::assertStringNotContainsString('@Orm\ForeignKeyAction', $code);
			self::assertStringContainsString('protected ?string $name', $code);
		}

		// -------------------------------------------------------------------------
		// A FK referencing a non-primary-key column round-trips the real column
		// -------------------------------------------------------------------------

		public function testForeignKeyReferencingANonPrimaryKeyColumnRoundTripsTheRealReferencedColumn(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
			$adapter->execute(
				'CREATE TABLE orders (' .
				'id INTEGER PRIMARY KEY, ' .
				'customer_email TEXT NOT NULL, ' .
				'FOREIGN KEY (customer_email) REFERENCES customers(email)' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'orders', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\CustomersEntity", referencedColumn="email")',
				$code
			);
		}

		// -------------------------------------------------------------------------
		// Multi-word snake_case table/column names resolve correctly
		// -------------------------------------------------------------------------

		public function testMultiWordTableAndColumnNamesResolveToCorrectCamelCaseTargetAndProperty(): void {
			[$command, $provider] = $this->makeCommand(true);
			$adapter = $provider->getDatabaseAdapter();

			$adapter->execute('CREATE TABLE product_categories (id INTEGER PRIMARY KEY)');
			$adapter->execute(
				'CREATE TABLE order_items (' .
				'id INTEGER PRIMARY KEY, ' .
				'product_category_id INTEGER NOT NULL, ' .
				'FOREIGN KEY (product_category_id) REFERENCES product_categories(id)' .
				')'
			);

			$code = $this->generateEntityCode($command, $provider, 'order_items', true);

			self::assertStringContainsString(
				'@Orm\ForeignKey(target="App\\Entities\\ProductCategoriesEntity", referencedColumn="id")',
				$code
			);
			self::assertStringContainsString('protected int $productCategoryId;', $code);
		}
	}
