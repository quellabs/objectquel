<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Invalid case: Cascade(strategy="database") with NO matching @Orm\ForeignKey
	 * anywhere for the relation's local column. Metadata build must throw.
	 * @Orm\Table(name="fk_orders_no_fk")
	 */
	class FkOrderNoFkEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=FkCustomerEntity::class, referencedColumn="id", localColumn="customerId", fetch="EAGER")
		 * @Orm\Cascade(strategy="database")
		 */
		public ?FkCustomerEntity $customer;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;
	}
