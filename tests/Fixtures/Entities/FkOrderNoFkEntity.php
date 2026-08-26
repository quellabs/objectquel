<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Relation + Cascade + ForeignKey, but no ForeignKeyAction. Valid: Cascade no
	 * longer has any opinion about ForeignKey at all (the two are fully
	 * independent, like Doctrine's cascade vs. @JoinColumn(onDelete=...)), so this
	 * builds successfully with the constraint left at its safe defaults
	 * (RESTRICT / NO ACTION).
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
		 * @Orm\Cascade(operations={"remove"})
		 * @Orm\ForeignKey(target=FkCustomerEntity::class)
		 */
		public ?FkCustomerEntity $customer;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;
	}
