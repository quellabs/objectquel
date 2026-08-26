<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * The non-owning side of the OneToOne pairs below (RelProfileEntity,
	 * RelProfileUnidirectionalEntity). Plain entity — the FK, Cascade and
	 * ForeignKey/ForeignKeyAction all live on the owning side.
	 * @Orm\Table(name="rel_users")
	 */
	class RelUserEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
