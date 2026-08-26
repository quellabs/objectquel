<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Metadata;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderBothNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderOrmEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Part 1.2 — EntityMetadataBuilder: ForeignKey parsing + the
	 * Cascade(strategy="database"|"both") validation. Pure metadata/annotation
	 * work; no database is touched anywhere in this test class.
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
			self::assertSame('CASCADE', $fk->getOnDelete());
			self::assertSame('NO ACTION', $fk->getOnUpdate());
		}

		public function testForeignKeyDeclaredOnScalarColumnPropertyIsKeyedByDatabaseColumnName(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarEntity::class);

			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());

			// Left at their annotation defaults — referencedColumn defaults are
			// resolved later, at migration-generation time, not here.
			self::assertNull($fk->getReferencedColumn());
			self::assertSame('RESTRICT', $fk->getOnDelete());
			self::assertSame('NO ACTION', $fk->getOnUpdate());
		}

		public function testCascadeDatabaseWithoutMatchingForeignKeyThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('customer_id');

			$this->makeFkEntityStore()->getMetadata(FkOrderNoFkEntity::class);
		}

		public function testCascadeBothWithoutMatchingForeignKeyAlsoThrows(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/Cascade\(strategy="both"\)/');

			$this->makeFkEntityStore()->getMetadata(FkOrderBothNoFkEntity::class);
		}

		public function testCascadeOrmNeverRequiresAForeignKey(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderOrmEntity::class);

			// Builds without throwing, and — since none was declared — carries no
			// foreign key metadata for the relation's column.
			self::assertArrayNotHasKey('customer_id', $metadata->foreignKeys);
		}

		public function testEntityWithNoForeignKeyAnnotationsAtAllHasEmptyForeignKeysArray(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkCustomerEntity::class);

			self::assertSame([], $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyForColumn('id'));
		}
	}
