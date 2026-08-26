<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Reached from RelPostTagEntity via a LAZY relation, to verify a bridge's own
	 * relation only gets the extra eager hop when it's not marked LAZY.
	 * @Orm\Table(name="rel_categories")
	 */
	class RelCategoryEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
