<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Part 1.1, revised — @Orm\ForeignKey annotation: parameter validation and
	 * defaults. Pure structure only (target, referencedColumn) — the ON DELETE/ON
	 * UPDATE behavior lives on the separate @Orm\ForeignKeyAction annotation, see
	 * ForeignKeyActionTest.
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
		}

		public function testExplicitParametersAreHonored(): void {
			$fk = new ForeignKey([
				'target'           => 'App\\Entities\\CustomerEntity',
				'referencedColumn' => 'uuid',
			]);

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertSame('uuid', $fk->getReferencedColumn());
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
			$params = ['target' => 'App\\Entities\\CustomerEntity', 'referencedColumn' => 'uuid'];
			$fk = new ForeignKey($params);

			self::assertSame($params, $fk->getParameters());
		}
	}
