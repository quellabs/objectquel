<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Declares no @Orm\ForeignKey at all. Paired in tests against a live table
	 * that already has a real foreign key constraint on customer_id, to exercise
	 * the "removed" side of the foreign-key diff (annotation dropped -> migration
	 * emits dropForeignKey()).
	 * @Orm\Table(name="fk_orders_removed")
	 */
	class FkOrderRemovedEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;
	}
