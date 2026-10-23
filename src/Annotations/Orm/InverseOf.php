<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * Declares a hydration target for an inverse relationship.
	 *
	 * This annotation does not define a relationship — it tells the hydrator
	 * where to place objects that point back to this entity via a ManyToOne
	 * or OneToOne declaration on the dependent entity.
	 *
	 * The relationship itself is always owned by the dependent entity through
	 * its ManyToOne or OneToOne annotation. InverseOf is purely a hydration
	 * instruction: "when you find instances of $targetEntity whose $via
	 * property points to me, put them in this collection."
	 *
	 * Example:
	 *
	 *   class UserEntity {
	 *       // @Orm\InverseOf(targetEntity=PostEntity::class, via="user")
	 *       public array $posts = [];
	 *   }
	 *
	 *   class PostEntity {
	 *       // @Orm\ManyToOne(targetEntity=UserEntity::class, inversedBy="id", fetch="EAGER")
	 *       public ?UserEntity $user = null;
	 *   }
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class InverseOf implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		/**
		 * Fully qualified class name of the entity that owns the relationship.
		 * This is the entity that declares the ManyToOne or OneToOne pointing
		 * back at the entity that declares this InverseOf annotation.
		 * @var string
		 */
		private string $targetEntity;

		/**
		 * Property name on $targetEntity whose value points to this entity.
		 * Used by the hydrator to match joined rows to this collection.
		 * @var string
		 */
		private string $relation;

		/**
		 * Constructs the InverseOf annotation.
		 * @param string $targetEntity
		 * @param string $relation
		 * @return void
		 */
		public function __construct(string $targetEntity, string $relation) {
			if ($targetEntity === '') {
				throw new \InvalidArgumentException(
					'InverseOf annotation requires a "targetEntity" parameter'
				);
			}

			if ($relation === '') {
				throw new \InvalidArgumentException(
					'InverseOf annotation requires a "relation" parameter'
				);
			}

			$this->targetEntity = $targetEntity;
			$this->relation = $relation;

		}





		/**
		 * Returns the fully qualified class name of the entity that owns the relationship.
		 * @return string
		 */
		public function getTargetEntity(): string {
			return $this->targetEntity;
		}

		/**
		 * Sets the fully qualified class name of the entity that owns the relationship.
		 * Called by EntityMetadataBuilder to normalize short class names to fully qualified ones.
		 * @param string $targetEntity
		 * @return void
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->targetEntity = $targetEntity;
		}

		/**
		 * Returns the property name on the target entity that points back to this entity.
		 * The hydrator uses this to match joined rows to this collection property.
		 * @return string
		 */
		public function getRelation(): string {
			return $this->relation;
		}
	}
