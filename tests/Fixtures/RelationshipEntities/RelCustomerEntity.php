<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * The "one" side of a ManyToOne+InverseOf one-to-many pair — this ORM has no
	 * standalone OneToMany annotation; InverseOf on a collection property is how
	 * the inverse/collection side is declared. See RelOrderCascadeEntity for the
	 * owning ManyToOne side that actually carries Cascade/ForeignKey.
	 *
	 * Kept in its own isolated fixture directory (tests/Fixtures/RelationshipEntities,
	 * not the shared tests/Fixtures/Entities used by the metadata-only FK test
	 * suites) because end-to-end cascade-remove tests call remove()+flush(),
	 * which triggers EntityStore::getOrderedDependentEntities() — this eagerly
	 * builds metadata for every entity in the configured entity_path, including
	 * ones like FkOrderActionNoFkEntity that are deliberately invalid for other
	 * tests' purposes. Sharing a directory with those would break every
	 * cascade-remove test here.
	 * @Orm\Table(name="rel_customers")
	 */
	class RelCustomerEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelOrderCascadeEntity::class, relation="customer")
		 */
		public CollectionInterface $orders;

		public function __construct() {
			$this->orders = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
