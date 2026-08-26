<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * The near side of a many-to-many modeled via RelPostTagEntity (the bridge)
	 * and RelTagEntity (the far side). $postTags is an ordinary InverseOf
	 * collection of bridge rows; reaching a tag is $post->postTags[0]->tag.
	 * @Orm\Table(name="rel_posts")
	 */
	class RelPostEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelPostTagEntity::class, relation="post")
		 */
		public CollectionInterface $postTags;

		public function __construct() {
			$this->postTags = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
