<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Metadata;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderActionNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderBothNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderOrmEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Part 1.2, revised — EntityMetadataBuilder: ForeignKey (pure structure),
	 * ForeignKeyAction (the ON DELETE/ON UPDATE behavior) and Cascade (PHP-side
	 * object-graph behavior only) are three fully independent annotations. Pure
	 * metadata/annotation work; no database is touched anywhere in this test class.
	 */
	class EntityMetadataBuilderForeignKeyTest extends TestCase {
		use FkTestSupport;

		public function testForeignKeyDeclaredOnRelationPropertyIsKeyedByDatabaseColumnName(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderEntity::class);

			// Keyed by the real DB column ('customer_id'), not the PHP property
			// name ('customer' or 'customerId') and not the ManyToOne localColumn
			// convention (which is itself a property name, see resolveLocalColumnName()).
			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);
			self::assertArrayNotHasKey('customer', $metadata->foreignKeys);
			self::assertArrayNotHasKey('customerId', $metadata->foreignKeys);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());
			self::assertSame('id', $fk->getReferencedColumn());
		}

		public function testForeignKeyDeclaredOnScalarColumnPropertyIsKeyedByDatabaseColumnName(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarEntity::class);

			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());

			// referencedColumn defaults are resolved later, at migration-generation
			// time, not here.
			self::assertNull($fk->getReferencedColumn());

			// No ForeignKeyAction declared at all — safe defaults apply downstream,
			// but there's simply no annotation object here to carry them.
			self::assertNull($metadata->getForeignKeyActionForColumn('customer_id'));
		}

		public function testForeignKeyActionOnAPlainScalarColumnRequiresNoCascadeAtAll(): void {
			// The case the ForeignKey/ForeignKeyAction split exists to support:
			// a real, non-default database action with zero object-relation
			// modeling and zero Cascade annotation anywhere.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarActionEntity::class);

			$action = $metadata->getForeignKeyActionForColumn('customer_id');
			self::assertNotNull($action);
			self::assertSame('CASCADE', $action->getOnDelete());
			self::assertSame('RESTRICT', $action->getOnUpdate());
		}

		public function testForeignKeyActionWithoutMatchingForeignKeyThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('customer_id');

			$this->makeFkEntityStore()->getMetadata(FkOrderActionNoFkEntity::class);
		}

		public function testCascadeWithoutAnyRelationThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/ManyToOne.*OneToOne/');

			$this->makeFkEntityStore()->getMetadata(FkOrderOrmEntity::class);
		}

		public function testCascadeAndForeignKeyOnARelationRequireNoForeignKeyAction(): void {
			// Cascade no longer has any opinion about ForeignKey at all — this
			// builds successfully with the constraint left at its safe defaults.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderNoFkEntity::class);

			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyActionForColumn('customer_id'));
		}

		public function testCascadeOnARelationRequiresNoForeignKeyAtAll(): void {
			// Pure PHP-side cascade removal, no database constraint declared for
			// this column at all — also valid, since Cascade and ForeignKey are
			// fully independent.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderBothNoFkEntity::class);

			self::assertArrayNotHasKey('customer_id', $metadata->foreignKeys);
		}

		public function testEntityWithNoForeignKeyAnnotationsAtAllHasEmptyForeignKeysArray(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkCustomerEntity::class);

			self::assertSame([], $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyForColumn('id'));
		}
	}
