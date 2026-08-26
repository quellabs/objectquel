<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Negative control for the ManyToOne+InverseOf one-to-many cascade-remove
	 * test: same shape as RelOrderCascadeEntity but with no Cascade annotation at
	 * all, proving removal of the parent does NOT cascade when Cascade is absent.
	 * @Orm\Table(name="rel_orders_no_cascade")
	 */
	class RelOrderNoCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelCustomerEntity::class, localColumn="customerId", fetch="EAGER")
		 */
		public ?RelCustomerEntity $customer = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
