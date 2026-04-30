<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	
	/**
	 * Injects discriminator conditions into the WHERE clause for single-table inheritance.
	 *
	 * When a range targets an entity that has a @DiscriminatorValue annotation,
	 * this class appends `AND <discriminator_column> = '<value>'` to the query's
	 * WHERE clause automatically.
	 *
	 * Called directly by QueryTransformer, which iterates ranges itself —
	 * there is no need to traverse the full AST since ranges only ever
	 * appear in AstRetrieve::$ranges.
	 */
	class DiscriminatorConditionInjector {
		
		/**
		 * @var EntityStore Entity metadata registry
		 */
		private EntityStore $entityStore;
		
		/**
		 * DiscriminatorConditionInjector constructor.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Inspect a single range and, if it maps to an STI subclass, inject the
		 * discriminator condition into the retrieve's WHERE clause.
		 * @param AstRangeDatabase $range The range to inspect
		 * @param AstRetrieve $retrieve The query whose conditions will be updated
		 * @return void
		 */
		public function process(AstRangeDatabase $range, AstRetrieve $retrieve): void {
			$entityName = $range->getEntityName();
			
			if (empty($entityName)) {
				return;
			}
			
			$discriminatorInfo = $this->getDiscriminatorInfo($entityName);
			
			if ($discriminatorInfo === null) {
				return;
			}
			
			$condition = $this->buildDiscriminatorCondition(
				$range->getName(),
				$discriminatorInfo['column'],
				$discriminatorInfo['value']
			);
			
			$condition->setParent($retrieve);
			
			$existingConditions = $retrieve->getConditions();
			
			if ($existingConditions === null) {
				$retrieve->setConditions($condition);
			} else {
				// Prepend the discriminator condition so it appears at the start of the WHERE
				// clause and can be used as an early filter by the query optimizer.
				$combined = new AstBinaryOperator($condition, $existingConditions, 'AND');
				$combined->setParent($retrieve);
				$retrieve->setConditions($combined);
			}
		}
		
		/**
		 * Resolve discriminator metadata for a given entity class.
		 *
		 * getClassAnnotations() already traverses the full inheritance chain (parent to
		 * child), so a single call on the subclass returns both @DiscriminatorValue
		 * (declared on the subclass) and @DiscriminatorColumn (declared on the parent).
		 *
		 * @param string $entityName Fully qualified entity class name
		 * @return array|null ['column' => columnName, 'value' => discriminatorValue], or null if not an STI subclass
		 */
		private function getDiscriminatorInfo(string $entityName): ?array {
			// One call covers both the subclass and all parent class annotations
			$classAnnotations    = $this->entityStore->getAnnotationReader()->getClassAnnotations($entityName);
			$discriminatorValue  = $classAnnotations[DiscriminatorValue::class] ?? null;
			$discriminatorColumn = $classAnnotations[DiscriminatorColumn::class] ?? null;
			
			// Not an STI subclass if either annotation is missing
			if ($discriminatorValue === null || $discriminatorColumn === null) {
				return null;
			}
			
			$value      = $discriminatorValue->getValue();
			$columnName = $discriminatorColumn->getName();
			
			if ($value === '' || $columnName === '') {
				return null;
			}
			
			return ['column' => $columnName, 'value' => $value];
		}
		
		/**
		 * Build the AST subtree for `<rangeAlias>.<columnName> = '<value>'`.
		 * @param string $rangeAlias The range alias (e.g. 't')
		 * @param string $columnName The discriminator column name (e.g. 'type')
		 * @param string $value      The discriminator value (e.g. 'truck')
		 * @return AstExpression
		 */
		private function buildDiscriminatorCondition(string $rangeAlias, string $columnName, string $value): AstExpression {
			$rangeIdentifier  = new AstIdentifier($rangeAlias);
			$columnIdentifier = new AstIdentifier($columnName);
			$rangeIdentifier->setNext($columnIdentifier);
			
			$valueNode = new AstString($value, "'");
			
			return new AstExpression($rangeIdentifier, $valueNode, '=');
		}
	}