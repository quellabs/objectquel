<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityCommand;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntityModifier;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhpClassGenerator;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	/**
	 * Wires @Orm\ForeignKey / @Orm\ForeignKeyAction into make:entity's owning-side
	 * relationship flow (ManyToOne/OneToOne), mirroring the structure/behavior
	 * split MakeEntityFromTableCommand already applies on the schema→entity side.
	 *
	 * Gated by the same generate_foreign_keys config key (2.2): when off, the new
	 * prompt in MakeEntityCommand::attachForeignKeyConstraint() is never reached
	 * (buildOwningSideProperties() only calls it when the flag is on) — covered
	 * indirectly by never invoking it in the "off" tests below.
	 */
	class MakeEntityForeignKeyTest extends TestCase {

		private string $entityPath;

		protected function setUp(): void {
			$this->entityPath = sys_get_temp_dir() . '/oq_mk_entity_fk_' . uniqid();
			mkdir($this->entityPath, 0755, true);
		}

		protected function tearDown(): void {
			foreach (glob($this->entityPath . '/*.php') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($this->entityPath);
		}

		private function makeConfiguration(bool $generateForeignKeys = true): Configuration {
			$provider = new ServiceProvider();
			$provider->setConfig([
				'entity_namespace'      => 'App\\Entities',
				'entity_path'           => $this->entityPath,
				'generate_foreign_keys' => $generateForeignKeys,
			]);

			return $provider->getConfiguration();
		}

		/** @var resource|null The output stream from the most recent makeCommand() call, for reading back what was printed */
		private $lastOutputStream = null;

		private function makeCommand(bool $generateForeignKeys, string $scriptedInput = ''): MakeEntityCommand {
			$provider = new ServiceProvider();
			$provider->setConfig([
				'entity_namespace'      => 'App\\Entities',
				'entity_path'           => $this->entityPath,
				'generate_foreign_keys' => $generateForeignKeys,
			]);

			$inputStream = fopen('php://memory', 'r+');
			fwrite($inputStream, $scriptedInput);
			rewind($inputStream);

			$this->lastOutputStream = fopen('php://memory', 'w+');
			$output = new ConsoleOutput($this->lastOutputStream);
			$input = new ConsoleInput($output, $inputStream);

			return new MakeEntityCommand($input, $output, $provider);
		}

		private function readLastOutput(): string {
			rewind($this->lastOutputStream);
			return stream_get_contents($this->lastOutputStream) ?: '';
		}

		/**
		 * @return array{0: string, 1: string} [propertyName, relationshipType='ManyToOne']
		 */
		private function invokeBuildOwningSideProperties(MakeEntityCommand $command, string $targetEntity): array {
			$reflection = new \ReflectionClass($command);
			$method = $reflection->getMethod('buildOwningSideProperties');
			$method->setAccessible(true);

			return $method->invoke(
				$command,
				'customer',
				'ManyToOne',
				['targetEntity' => $targetEntity, 'referencedField' => 'id'],
				['relation' => null, 'referencedColumn' => null],
				false
			);
		}

		// -------------------------------------------------------------------------
		// Config gate — off means the prompt is never reached
		// -------------------------------------------------------------------------

		public function testGenerateForeignKeysOffNeverPromptsAndFkColumnCarriesNoForeignKeyData(): void {
			// Empty scripted input: if attachForeignKeyConstraint() were wrongly reached
			// despite the flag being off, choice() would hit EOF and fall back to its
			// default (RESTRICT/NO ACTION) rather than erroring — so 'foreignKey' WOULD
			// be populated in that failure mode, making this a real regression check.
			$command = $this->makeCommand(false);

			$properties = $this->invokeBuildOwningSideProperties($command, 'Customer');

			self::assertCount(2, $properties);
			[$relationProperty, $fkColumn] = $properties;

			self::assertSame('customer', $relationProperty['name']);
			self::assertSame('customerId', $fkColumn['name']);
			self::assertArrayNotHasKey('foreignKey', $fkColumn);
		}

		// -------------------------------------------------------------------------
		// Config gate on — the constraint is attached unconditionally, with the
		// safe defaults, and no interactive prompt at all (neither a per-column
		// confirm nor an ON DELETE/ON UPDATE choice)
		// -------------------------------------------------------------------------

		public function testGenerateForeignKeysOnAttachesSafeDefaultsWithNoPromptPrinted(): void {
			$command = $this->makeCommand(true);

			[, $fkColumn] = $this->invokeBuildOwningSideProperties($command, 'Customer');

			self::assertSame([
				'target'   => 'App\\Entities\\CustomerEntity',
				'onDelete' => 'RESTRICT',
				'onUpdate' => 'NO ACTION',
			], $fkColumn['foreignKey']);

			// Real proof there's no interactive step left: nothing about a constraint
			// or ON DELETE/ON UPDATE was ever written to output, so nothing could have
			// been read from input either.
			$output = $this->readLastOutput();
			self::assertStringNotContainsString('foreign key', strtolower($output));
			self::assertStringNotContainsString('ON DELETE', $output);
			self::assertStringNotContainsString('ON UPDATE', $output);
		}

		// -------------------------------------------------------------------------
		// PhpClassGenerator — annotation emission (2.5's presence-means-declared
		// convention, reused verbatim from MakeEntityFromTableCommand)
		// -------------------------------------------------------------------------

		public function testDocCommentWithSafeDefaultRuleEmitsOnlyForeignKeyNoAction(): void {
			$generator = new PhpClassGenerator();

			$docComment = $generator->generatePropertyDocComment([
				'name'       => 'customerId',
				'type'       => 'integer',
				'unsigned'   => true,
				'foreignKey' => [
					'target'   => 'App\\Entities\\CustomerEntity',
					'onDelete' => 'RESTRICT',
					'onUpdate' => 'NO ACTION',
				],
			]);

			self::assertStringContainsString('@Orm\\ForeignKey(target="App\\Entities\\CustomerEntity")', $docComment);
			self::assertStringNotContainsString('@Orm\\ForeignKeyAction', $docComment);
		}

		public function testDocCommentWithDeviatingRuleEmitsBothAnnotations(): void {
			$generator = new PhpClassGenerator();

			$docComment = $generator->generatePropertyDocComment([
				'name'       => 'customerId',
				'type'       => 'integer',
				'unsigned'   => true,
				'foreignKey' => [
					'target'   => 'App\\Entities\\CustomerEntity',
					'onDelete' => 'CASCADE',
					'onUpdate' => 'RESTRICT',
				],
			]);

			self::assertStringContainsString('@Orm\\ForeignKey(target="App\\Entities\\CustomerEntity")', $docComment);
			self::assertStringContainsString('@Orm\\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")', $docComment);
		}

		public function testDocCommentWithNoForeignKeyDataEmitsNeitherAnnotation(): void {
			$generator = new PhpClassGenerator();

			$docComment = $generator->generatePropertyDocComment([
				'name' => 'title',
				'type' => 'string',
			]);

			self::assertStringNotContainsString('@Orm\\ForeignKey', $docComment);
		}

		// -------------------------------------------------------------------------
		// EntityModifier — new entity file: imports + annotations end to end
		// -------------------------------------------------------------------------

		public function testNewEntityFileImportsForeignKeyAndForeignKeyActionUnconditionally(): void {
			$configuration = $this->makeConfiguration();
			$modifier = new EntityModifier($configuration);

			$modifier->createOrUpdateEntity('Plain', []);

			$content = file_get_contents($this->entityPath . '/PlainEntity.php');

			self::assertStringContainsString('use Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKey;', $content);
			self::assertStringContainsString('use Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKeyAction;', $content);
		}

		public function testNewEntityFileWithFkColumnEmitsTheAnnotationOnThatProperty(): void {
			$configuration = $this->makeConfiguration();
			$modifier = new EntityModifier($configuration);

			$modifier->createOrUpdateEntity('Order', [
				[
					'name'       => 'customerId',
					'type'       => 'integer',
					'unsigned'   => true,
					'readonly'   => true,
					'foreignKey' => [
						'target'   => 'App\\Entities\\CustomerEntity',
						'onDelete' => 'CASCADE',
						'onUpdate' => 'NO ACTION',
					],
				],
			]);

			$content = file_get_contents($this->entityPath . '/OrderEntity.php');

			self::assertStringContainsString('@Orm\\ForeignKey(target="App\\Entities\\CustomerEntity")', $content);
			self::assertStringContainsString('@Orm\\ForeignKeyAction(onDelete="CASCADE", onUpdate="NO ACTION")', $content);
		}

		// -------------------------------------------------------------------------
		// EntityModifier — updating a pre-existing file that predates this feature
		// -------------------------------------------------------------------------

		public function testUpdatingAnEntityWithoutExistingForeignKeyImportsInjectsThemOnce(): void {
			$configuration = $this->makeConfiguration();
			$modifier = new EntityModifier($configuration);

			// Simulate an entity file written before this feature existed: no
			// ForeignKey/ForeignKeyAction imports at all.
			$legacyContent = "<?php\n\n\tnamespace App\\Entities;\n\n" .
				"\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;\n" .
				"\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;\n\n" .
				"\t/**\n\t * @Orm\\Table(name=\"legacies\")\n\t */\n" .
				"\tclass LegacyEntity {\n" .
				"\t\t/**\n\t\t * @Orm\\Column(name=\"id\", type=\"integer\", unsigned=true, primary_key=true)\n\t\t */\n" .
				"\t\tprotected ?int \$id = null;\n" .
				"\t}\n";
			file_put_contents($this->entityPath . '/LegacyEntity.php', $legacyContent);

			$modifier->createOrUpdateEntity('Legacy', [
				[
					'name'       => 'ownerId',
					'type'       => 'integer',
					'unsigned'   => true,
					'readonly'   => true,
					'foreignKey' => [
						'target'   => 'App\\Entities\\OwnerEntity',
						'onDelete' => 'RESTRICT',
						'onUpdate' => 'NO ACTION',
					],
				],
			]);

			$content = file_get_contents($this->entityPath . '/LegacyEntity.php');

			self::assertSame(1, substr_count($content, 'use Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKey;'));
			// ForeignKeyAction should NOT be imported: onDelete/onUpdate here are the
			// safe defaults, so generatePropertyDocComment() never emits that annotation.
			self::assertStringNotContainsString('Orm\\ForeignKeyAction;', $content);
			self::assertStringContainsString('@Orm\\ForeignKey(target="App\\Entities\\OwnerEntity")', $content);

			// Re-running against the now-updated file must not duplicate the import.
			$modifier->createOrUpdateEntity('Legacy', [
				[
					'name'       => 'reviewerId',
					'type'       => 'integer',
					'unsigned'   => true,
					'readonly'   => true,
					'foreignKey' => [
						'target'   => 'App\\Entities\\OwnerEntity',
						'onDelete' => 'RESTRICT',
						'onUpdate' => 'NO ACTION',
					],
				],
			]);

			$updatedContent = file_get_contents($this->entityPath . '/LegacyEntity.php');
			self::assertSame(1, substr_count($updatedContent, 'use Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKey;'));
		}
	}
