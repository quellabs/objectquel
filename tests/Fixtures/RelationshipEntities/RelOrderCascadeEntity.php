<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * The owning ("many") side of a ManyToOne+InverseOf one-to-many pair, paired
	 * with RelCustomerEntity's InverseOf collection. Carries the full stack:
	 * ManyToOne + Cascade(remove) (PHP-side cascade when the customer is removed)
	 * + ForeignKey/ForeignKeyAction (a real ON DELETE CASCADE constraint) — proves
	 * both cascade systems work for the genuine one-to-many shape a real user
	 * would declare via make:entity's bidirectional flow, not just a bare
	 * ManyToOne with no InverseOf mirror on the other side.
	 * @Orm\Table(name="rel_orders_cascade")
	 */
	class RelOrderCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelCustomerEntity::class, localColumn="customerId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
		 * @Orm\ForeignKey(target=RelCustomerEntity::class, referencedColumn="id")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
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
