<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Valid case: Cascade(strategy="database") on the ManyToOne relation, paired
	 * with an @Orm\ForeignKey stacked on the same relation property. Exercises
	 * the "ForeignKey declared on the relation property itself" path.
	 * @Orm\Table(name="fk_orders")
	 */
	class FkOrderEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=FkCustomerEntity::class, referencedColumn="id", localColumn="customerId", fetch="EAGER")
		 * @Orm\Cascade(strategy="database")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class, referencedColumn="id", onDelete="CASCADE")
		 */
		public ?FkCustomerEntity $customer;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
