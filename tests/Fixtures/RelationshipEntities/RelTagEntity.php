<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * The far side of a many-to-many modeled via RelPostTagEntity (the bridge)
	 * and RelPostEntity (the near side). A plain entity with no relation back to
	 * the bridge — reaching it is entirely QueryBuilder's job.
	 * @Orm\Table(name="rel_tags")
	 */
	class RelTagEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
