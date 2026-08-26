<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * @Orm\ForeignKeyAction annotation: the ON DELETE/ON UPDATE vocabulary that
	 * used to live on @Orm\ForeignKey itself, now split into its own annotation so
	 * a real database action can be declared independently of both ForeignKey's
	 * structural role and @Orm\Cascade's PHP-side behavior.
	 */
	class ForeignKeyActionTest extends TestCase {

		public function testDefaultsAreAppliedWhenOmitted(): void {
			$action = new ForeignKeyAction([]);

			self::assertSame('RESTRICT', $action->getOnDelete());
			self::assertSame('NO ACTION', $action->getOnUpdate());
		}

		public function testExplicitParametersAreHonored(): void {
			$action = new ForeignKeyAction(['onDelete' => 'CASCADE', 'onUpdate' => 'SET NULL']);

			self::assertSame('CASCADE', $action->getOnDelete());
			self::assertSame('SET NULL', $action->getOnUpdate());
		}

		/**
		 * @dataProvider validActionProvider
		 */
		public function testAllFourActionsAreAcceptedForOnDeleteAndOnUpdate(string $action): void {
			$fkAction = new ForeignKeyAction(['onDelete' => $action, 'onUpdate' => $action]);

			self::assertSame($action, $fkAction->getOnDelete());
			self::assertSame($action, $fkAction->getOnUpdate());
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

			new ForeignKeyAction(['onDelete' => 'SET_NULL']);
		}

		public function testInvalidOnUpdateValueIsRejected(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'onUpdate' must be one of");

			new ForeignKeyAction(['onUpdate' => 'DO_NOTHING']);
		}

		public function testGetParametersReturnsOriginalParameterArray(): void {
			$params = ['onDelete' => 'CASCADE', 'onUpdate' => 'RESTRICT'];
			$action = new ForeignKeyAction($params);

			self::assertSame($params, $action->getParameters());
		}
	}
