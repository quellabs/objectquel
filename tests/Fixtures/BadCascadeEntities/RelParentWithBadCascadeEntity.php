<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\BadCascadeEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * Deliberately invalid, by design: Cascade(persist) placed directly on an
	 * InverseOf collection property. InverseOf is a hydration instruction, not a
	 * relation — it never owns the FK (the ManyToOne/OneToOne on the dependent
	 * entity does), so Cascade has nothing to walk or persist/remove there.
	 * EntityMetadataBuilder::validateCascadeRequiresRelation() rejects this at
	 * metadata-build time with a RuntimeException.
	 *
	 * (UnitOfWork::processCascadingInverseOfPersists() does contain code that
	 * reads a Cascade off an InverseOf property, but since that annotation
	 * combination can never pass validation, the branch is unreachable in
	 * practice — dead code, not a working feature this fixture is missing out on.)
	 *
	 * Kept in its own isolated fixture directory (tests/Fixtures/BadCascadeEntities,
	 * not the shared tests/Fixtures/Entities used by every other FK/cascade test)
	 * because any cascade-remove test that shares an EntityStore with this class
	 * would trip over it too: EntityStore::getOrderedDependentEntities() eagerly
	 * builds metadata for every entity in the registry the first time any
	 * cascade-remove path runs, not just the one entity being removed.
	 * @Orm\Table(name="rel_parents_bad_cascade")
	 */
	class RelParentWithBadCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelChildOfBadCascadeEntity::class, relation="parent")
		 * @Orm\Cascade(operations={"persist"})
		 */
		public CollectionInterface $children;

		public function __construct() {
			$this->children = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
