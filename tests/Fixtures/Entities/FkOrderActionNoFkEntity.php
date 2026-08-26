<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * Invalid case: @Orm\ForeignKeyAction with no matching @Orm\ForeignKey on the
	 * same column. ForeignKeyAction only configures an existing constraint's
	 * ON DELETE/ON UPDATE behavior — it has nothing to configure here. Metadata
	 * build must throw.
	 * @Orm\Table(name="fk_orders_action_no_fk")
	 */
	class FkOrderActionNoFkEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
		 */
		protected ?int $customerId = null;
	}
