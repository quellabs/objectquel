<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Same owning OneToOne + Cascade(remove) combo as RelProfileEntity, but with
	 * no 'referencedColumn' — i.e. unidirectional. Proves cascade-remove works
	 * here too: UnitOfWork::handleDependentEntityClass() used to filter OneToOne
	 * dependents down to only those with a non-empty referencedColumn before
	 * checking Cascade at all, silently skipping this exact case — fixed, since
	 * referencedColumn only affects bidirectional setter sync codegen and has
	 * nothing to do with cascade-remove eligibility.
	 * @Orm\Table(name="rel_profiles_unidirectional")
	 */
	class RelProfileUnidirectionalEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\OneToOne(targetEntity=RelUserEntity::class, localColumn="userId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
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
