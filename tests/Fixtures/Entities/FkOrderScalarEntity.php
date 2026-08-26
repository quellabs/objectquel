<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * No ManyToOne/OneToOne at all — a plain scalar FK column, the style this ORM
	 * is designed to tolerate for projects that never adopt object relations.
	 * referencedColumn/onDelete/onUpdate are all left at their defaults.
	 * @Orm\Table(name="fk_orders_scalar")
	 */
	class FkOrderScalarEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class)
		 */
		protected ?int $customerId = null;
	}
