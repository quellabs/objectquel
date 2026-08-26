<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\BadCascadeEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * The owning side paired with RelParentWithBadCascadeEntity — see that
	 * class's docblock for what this pair reproduces.
	 * @Orm\Table(name="rel_children_bad_cascade")
	 */
	class RelChildOfBadCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelParentWithBadCascadeEntity::class, localColumn="parentId", fetch="EAGER")
		 */
		public ?RelParentWithBadCascadeEntity $parent = null;

		/**
		 * @Orm\Column(name="parent_id", type="integer")
		 */
		protected ?int $parentId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
