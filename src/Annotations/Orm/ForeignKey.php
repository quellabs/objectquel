<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\UnsuppliedParameter;
	
	/**
	 * @Annotation
	 * Declares a real database-level foreign key constraint.
	 *
	 * At property level, legal only on a plain @Orm\Column-backed scalar
	 * property — never on a ManyToOne/OneToOne relation property. A relation's
	 * local column is already required to have its own @Orm\Column-backed
	 * scalar property (see EntityMetadataBuilder::validateRelationColumns()),
	 * so that scalar property is where @Orm\ForeignKey belongs even for a
	 * column that also backs an object relation.
	 *
	 * Also legal at class level, for a constraint spanning more than one
	 * column (see EntityMetadataBuilder's class-level extraction).
	 *
	 * Purely structural — target plus either referencedColumn or columns. The
	 * constraint's ON DELETE/ON UPDATE behavior is a separate concern,
	 * declared via @Orm\ForeignKeyAction (same property, or same class with a
	 * matching localColumns list) when a non-default action is needed.
	 *
	 * Two column shapes, mutually exclusive:
	 * - referencedColumn (legacy, property level only): the target property
	 *   this (implicit, single) local column points to, or null to default
	 *   to the target's primary key.
	 * - columns (a local-property => target-property map): at property
	 *   level, exactly one entry whose key equals the declaring property's
	 *   own name — the same single-column case, restated in this shape. At
	 *   class level, one entry per participating column, in constraint order.
	 *
	 * Examples:
	 * @Orm\ForeignKey(target=UserEntity::class, referencedColumn="id")
	 * @Orm\ForeignKey(target=UserEntity::class, columns={"userId"="id"})
	 * @Orm\ForeignKey(target=OrderEntity::class, columns={"orderTenantId"="tenantId", "orderNumber"="number"})
	 */
	#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class ForeignKey implements AnnotationInterface {
		
		private string $target;
		private ?string $referencedColumn;
		
		/**
		 * @var array<string, string> Local property => target property, in declaration order; empty when using the legacy referencedColumn form
		 */
		private ?array $columns;
		
		/**
		 * @var array<string, mixed> Only the keys the caller actually supplied
		 */
		private array $parameters;
		
		/**
		 * ForeignKey constructor.
		 * @param string $target Target entity
		 * @param string|UnsuppliedParameter|null $referencedColumn Referenced property
		 * @param array<array-key, mixed>|UnsuppliedParameter|null $columns Local-to-target property map
		 * @throws \InvalidArgumentException
		 */
		public function __construct(string $target, string|UnsuppliedParameter|null $referencedColumn = new UnsuppliedParameter(), array|UnsuppliedParameter|null $columns = new UnsuppliedParameter()) {
			if ($target === '') {
				throw new \InvalidArgumentException("ForeignKey: 'target' must be a non-empty string");
			}
			
			$resolvedReferencedColumn = $referencedColumn instanceof UnsuppliedParameter ? null : $referencedColumn;
			$resolvedColumns = $columns instanceof UnsuppliedParameter ? null : $columns;
			
			if ($resolvedColumns !== null && $resolvedReferencedColumn !== null) {
				throw new \InvalidArgumentException("ForeignKey: 'columns' cannot be combined with 'referencedColumn' on the same declaration");
			}
			
			$validatedColumns = [];
			
			if ($resolvedColumns !== null) {
				if ($resolvedColumns === []) {
					throw new \InvalidArgumentException("ForeignKey: 'columns' must be a non-empty map of local property to target property");
				}
				
				$seenTargets = [];
				
				foreach ($resolvedColumns as $localProperty => $targetProperty) {
					if (!is_string($localProperty) || $localProperty === '') {
						throw new \InvalidArgumentException("ForeignKey: 'columns' keys must be non-empty local property names");
					}
					
					if (!is_string($targetProperty) || $targetProperty === '') {
						throw new \InvalidArgumentException("ForeignKey: 'columns' values must be non-empty target property names");
					}
					
					if (isset($seenTargets[$targetProperty])) {
						throw new \InvalidArgumentException("ForeignKey: 'columns' target property '{$targetProperty}' is declared more than once");
					}
					
					$seenTargets[$targetProperty] = true;
					$validatedColumns[$localProperty] = $targetProperty;
				}
			}
			
			$this->target = $target;
			$this->referencedColumn = $resolvedReferencedColumn;
			$this->columns = $resolvedColumns === null ? null : $validatedColumns;
			
			$this->parameters = ['target' => $target];
			
			if (!($referencedColumn instanceof UnsuppliedParameter)) {
				$this->parameters['referencedColumn'] = $referencedColumn;
			}
			
			if (!($columns instanceof UnsuppliedParameter)) {
				$this->parameters['columns'] = $columns;
			}
		}
		
		/**
		 * Returns only the parameters the caller actually supplied
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Retrieves the target entity.
		 * @return string The fully qualified class name of the referenced entity.
		 */
		public function getTarget(): string {
			return $this->target;
		}
		
		/**
		 * Overwrites the target entity, e.g. after resolving a short class name to its
		 * fully qualified form. Mirrors ManyToOne::setTargetEntity().
		 * @param string $target
		 * @return void
		 */
		public function setTarget(string $target): void {
			$this->target = $target;
			$this->parameters['target'] = $target;
		}
		
		/**
		 * Retrieves the 'referencedColumn' parameter, if present.
		 * @return string|null The column on the target entity this key points to,
		 *                     or null when it should default to the target's primary key.
		 */
		public function getReferencedColumn(): ?string {
			if ($this->referencedColumn !== null) {
				return $this->referencedColumn;
			}
			
			// 'columns' restates the single-column case in the new shape — fall
			// back to its one entry so single-column callers never need to care
			// which form was used to declare it. A genuinely composite (>1 entry)
			// declaration has no single "the" referenced column; such callers
			// must use getColumns() instead.
			$columns = $this->getColumns();
			return count($columns) === 1 ? reset($columns) : null;
		}
		
		/**
		 * Retrieves the 'columns' parameter: local property name => target
		 * property name, in declaration order. Empty when this ForeignKey uses
		 * the legacy singular 'referencedColumn' form instead.
		 * @return array<string, string>
		 */
		public function getColumns(): array {
			return $this->columns ?? [];
		}
		
		/**
		 * True when this ForeignKey was declared with the 'columns' map, false
		 * for the legacy singular 'referencedColumn' form (or no column
		 * information at all, defaulting to the target's primary key).
		 * @return bool
		 */
		public function hasColumns(): bool {
			return $this->columns !== null && $this->columns !== [];
		}
	}
