<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * The owning side of a bidirectional OneToOne. 'referencedColumn' being
	 * non-empty is what UnitOfWork::handleDependentEntityClass() treats as
	 * "bidirectional" and requires before a OneToOne dependent is even
	 * considered for cascade-remove — see RelProfileUnidirectionalEntity for
	 * the negative control that omits it. Carries the same Cascade(remove,
	 * persist) + ForeignKey/ForeignKeyAction combo FkOrderEntity proves for
	 * ManyToOne, but for OneToOne, which no existing fixture ever exercised.
	 * @Orm\Table(name="rel_profiles")
	 */
	class RelProfileEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, referencedColumn="profile", localColumn="userId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove", "persist"})
		 * @Orm\ForeignKey(target=RelUserEntity::class, referencedColumn="id")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
		 */
		public ?RelUserEntity $user = null;

		/**
		 * @Orm\Column(name="user_id", type="integer")
		 */
		protected ?int $userId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
