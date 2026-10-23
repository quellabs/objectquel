<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 * Declares the ON DELETE / ON UPDATE behavior of an @Orm\ForeignKey. Optional —
	 * a bare @Orm\ForeignKey with no ForeignKeyAction gets the safe defaults
	 * (RESTRICT / NO ACTION).
	 *
	 * At property level, matched to the @Orm\ForeignKey on the same property by
	 * colocation — localColumns is not used there. At class level, where a class
	 * may declare more than one @Orm\ForeignKey, localColumns (an ordered list of
	 * local property names) matches this action to exactly one of them: the
	 * @ForeignKey whose 'columns' keys, in order, equal this list.
	 *
	 * Deliberately independent of @Orm\Cascade: this configures what the database
	 * constraint itself does, not whether the ORM also walks the relation in PHP.
	 * The two are unrelated and can be combined freely, or used alone — a plain
	 * scalar @Orm\Column-backed FK column (no ManyToOne/OneToOne, no Cascade) can
	 * still declare a real ON DELETE CASCADE/SET NULL here.
	 *
	 * Examples:
	 * @Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")
	 * @Orm\ForeignKeyAction(localColumns={"orderTenantId", "orderNumber"}, onDelete="CASCADE")
	 */
	#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class ForeignKeyAction implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * @var array<int, string> Valid values for onDelete/onUpdate
		 */
		private const array VALID_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];
		
		private string $onDelete;
		private string $onUpdate;
		
		/**
		 * @var list<string> Ordered local property names, for matching a class-level @ForeignKey; empty at property level
		 */
		private ?array $localColumns;
		
		/**
		 * Constructs the ForeignKeyAction annotation.
		 * @param string $onDelete
		 * @param string $onUpdate
		 * @param array<array-key, mixed>|null $localColumns
		 * @return void
		 */
		public function __construct(string $onDelete = 'RESTRICT', string $onUpdate = 'NO ACTION', ?array $localColumns = null) {
			if (!in_array($onDelete, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKeyAction: 'onDelete' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}
			
			if (!in_array($onUpdate, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKeyAction: 'onUpdate' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}
			
			$normalizedLocalColumns = [];
			
			if ($localColumns !== null) {
				if ($localColumns === []) {
					throw new \InvalidArgumentException("ForeignKeyAction: 'localColumns' must be a non-empty list of local property names");
				}
				
				foreach ($localColumns as $property) {
					if (!is_string($property) || $property === '') {
						throw new \InvalidArgumentException("ForeignKeyAction: 'localColumns' entries must be non-empty strings");
					}
					
					$normalizedLocalColumns[] = $property;
				}
			}
			
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
			$this->localColumns = $localColumns === null ? null : $normalizedLocalColumns;
		}
		
		/**
		 * Returns the ON DELETE action (default: "RESTRICT")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnDelete(): string {
			return $this->onDelete;
		}
		
		/**
		 * Returns the ON UPDATE action (default: "NO ACTION")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnUpdate(): string {
			return $this->onUpdate;
		}
		
		/**
		 * Returns the 'localColumns' parameter: ordered local property names,
		 * for matching this action to one class-level @ForeignKey. Empty at
		 * property level, where colocation on the same property is the match.
		 * @return list<string>
		 */
		public function getLocalColumns(): array {
			return array_values($this->localColumns ?? []);
		}
		
		/**
		 * True when this ForeignKeyAction declared 'localColumns' (a class-level
		 * declaration), false for the property-level form.
		 * @return bool
		 */
		public function hasLocalColumns(): bool {
			return $this->localColumns !== null && $this->localColumns !== [];
		}
	}
