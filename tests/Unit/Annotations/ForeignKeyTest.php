<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Part 1.1 — @Orm\ForeignKey annotation: parameter validation and defaults.
	 */
	class ForeignKeyTest extends TestCase {

		public function testTargetIsRequired(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'target' must be a non-empty string");

			new ForeignKey([]);
		}

		public function testTargetMustBeNonEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);

			new ForeignKey(['target' => '']);
		}

		public function testDefaultsAreAppliedWhenOmitted(): void {
			$fk = new ForeignKey(['target' => 'App\\Entities\\CustomerEntity']);

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertNull($fk->getReferencedColumn());
			self::assertSame('RESTRICT', $fk->getOnDelete());
			self::assertSame('NO ACTION', $fk->getOnUpdate());
		}

		public function testExplicitParametersAreHonored(): void {
			$fk = new ForeignKey([
				'target'           => 'App\\Entities\\CustomerEntity',
				'referencedColumn' => 'uuid',
				'onDelete'         => 'CASCADE',
				'onUpdate'         => 'SET NULL',
			]);

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertSame('uuid', $fk->getReferencedColumn());
			self::assertSame('CASCADE', $fk->getOnDelete());
			self::assertSame('SET NULL', $fk->getOnUpdate());
		}

		/**
		 * @dataProvider validActionProvider
		 */
		public function testAllFourActionsAreAcceptedForOnDeleteAndOnUpdate(string $action): void {
			$fk = new ForeignKey([
				'target'   => 'App\\Entities\\CustomerEntity',
				'onDelete' => $action,
				'onUpdate' => $action,
			]);

			self::assertSame($action, $fk->getOnDelete());
			self::assertSame($action, $fk->getOnUpdate());
		}

		public static function validActionProvider(): array {
			return [
				'RESTRICT'  => ['RESTRICT'],
				'CASCADE'   => ['CASCADE'],
				'SET NULL'  => ['SET NULL'],
				'NO ACTION' => ['NO ACTION'],
			];
		}

		public function testInvalidOnDeleteValueIsRejected(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'onDelete' must be one of");

			new ForeignKey(['target' => 'App\\Entities\\CustomerEntity', 'onDelete' => 'SET_NULL']);
		}

		public function testInvalidOnUpdateValueIsRejected(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'onUpdate' must be one of");

			new ForeignKey(['target' => 'App\\Entities\\CustomerEntity', 'onUpdate' => 'DO_NOTHING']);
		}

		public function testReferencedColumnMustBeStringOrNull(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'referencedColumn' must be a string or null");

			new ForeignKey(['target' => 'App\\Entities\\CustomerEntity', 'referencedColumn' => 123]);
		}

		public function testSetTargetOverwritesTargetAndParameters(): void {
			$fk = new ForeignKey(['target' => 'CustomerEntity']);
			$fk->setTarget('App\\Entities\\CustomerEntity');

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertSame('App\\Entities\\CustomerEntity', $fk->getParameters()['target']);
		}

		public function testGetParametersReturnsOriginalParameterArray(): void {
			$params = ['target' => 'App\\Entities\\CustomerEntity', 'onDelete' => 'CASCADE'];
			$fk = new ForeignKey($params);

			self::assertSame($params, $fk->getParameters());
		}
	}
