<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * A plain scalar FK column (no ManyToOne/OneToOne, no Cascade at all) that
	 * still gets a real, non-default database action via ForeignKeyAction alone.
	 * This is the case the whole ForeignKey/ForeignKeyAction split exists to
	 * support: full referential-integrity enforcement without adopting any
	 * object-relation modeling.
	 * @Orm\Table(name="fk_orders_scalar_action")
	 */
	class FkOrderScalarActionEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class)
		 * @Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")
		 */
		protected ?int $customerId = null;
	}
