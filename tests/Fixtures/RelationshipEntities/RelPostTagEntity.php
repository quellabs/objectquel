<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\EntityBridge;

	/**
	 * A many-to-many between RelPostEntity and RelTagEntity, modeled as a real
	 * entity holding two ordinary ManyToOne relations, one to each side.
	 *
	 * @Orm\EntityBridge lets QueryBuilder eager-join one hop further through this
	 * entity's own relations when it's joined in as a dependent (see
	 * RelPostEntity::$postTags) — without it, $tag resolves lazily per row.
	 * @Orm\Table(name="rel_post_tags")
	 * @Orm\EntityBridge
	 */
	class RelPostTagEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelPostEntity::class, localColumn="postId", fetch="EAGER")
		 */
		public ?RelPostEntity $post = null;

		/**
		 * @Orm\Column(name="post_id", type="integer")
		 */
		protected ?int $postId = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelTagEntity::class, localColumn="tagId", fetch="EAGER")
		 */
		public ?RelTagEntity $tag = null;

		/**
		 * @Orm\Column(name="tag_id", type="integer")
		 */
		protected ?int $tagId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
