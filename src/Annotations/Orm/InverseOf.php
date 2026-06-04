<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
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
	class InverseOf implements AnnotationInterface {
		
		/**
		 * Array containing all column parameters
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
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
		private string $via;
		
		/**
		 * InverseOf constructor.
		 * @param array<string, mixed> $parameters Annotation parameters
		 * @throws \InvalidArgumentException When required parameters are missing or invalid
		 */
		public function __construct(array $parameters) {
			$targetEntity = $parameters['targetEntity'] ?? null;
			$via = $parameters['via'] ?? null;
			
			if (!is_string($targetEntity) || $targetEntity === '') {
				throw new \InvalidArgumentException(
					'InverseOf annotation requires a "targetEntity" parameter'
				);
			}
			
			if (!is_string($via) || $via === '') {
				throw new \InvalidArgumentException(
					'InverseOf annotation requires a "via" parameter'
				);
			}
			
			$this->parameters = $parameters;
			$this->targetEntity = $targetEntity;
			$this->via = $via;
		}
		
		/**
		 * Returns all parameters for this column annotation
		 * @return array<string, mixed> The complete parameters array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the fully qualified class name of the entity that owns the relationship.
		 * @return string
		 */
		public function getTargetEntity(): string {
			return $this->targetEntity;
		}
		
		/**
		 * Returns the property name on the target entity that points back to this entity.
		 * The hydrator uses this to match joined rows to this collection property.
		 * @return string
		 */
		public function getVia(): string {
			return $this->via;
		}
	}