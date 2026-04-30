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
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that injects discriminator conditions for single-table inheritance.
	 *
	 * When a range targets an entity that has a @DiscriminatorValue annotation, this
	 * visitor locates the @DiscriminatorColumn on the parent class and appends
	 * `AND <discriminator_column> = '<discriminator_value>'` to the query's WHERE clause.
	 *
	 * This runs as a transformer pass after entity names have been normalized (Step 2),
	 * so entity names are always fully qualified when we inspect annotations.
	 *
	 * Only AstRangeDatabase nodes are examined — JSON sources are never STI entities.
	 */
	class DiscriminatorConditionInjector implements AstVisitorInterface {
		
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
		 * Visit an AST node. Only acts on AstRangeDatabase nodes whose entity
		 * carries a @DiscriminatorValue annotation.
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// We only care about database ranges
			if (!$node instanceof AstRangeDatabase) {
				return;
			}
			
			$entityName = $node->getEntityName();
			
			if (empty($entityName)) {
				return;
			}
			
			// Look up the discriminator info for this entity.
			// Returns null when the entity is not an STI subclass.
			$discriminatorInfo = $this->getDiscriminatorInfo($entityName);
			
			if ($discriminatorInfo === null) {
				return;
			}
			
			// We have a discriminator column name and value — build the condition node
			// and AND it into the retrieve's WHERE clause.
			$columnName         = $discriminatorInfo['column'];
			$discriminatorValue = $discriminatorInfo['value'];
			
			$rangeAlias = $node->getName();
			$retrieve   = $this->getParentRetrieve($node);
			
			if ($retrieve === null) {
				return;
			}
			
			$condition = $this->buildDiscriminatorCondition($rangeAlias, $columnName, $discriminatorValue);
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
		 * @return array|null [columnName, discriminatorValue], or null if not an STI subclass
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
		 * Walk up the AST parent chain to find the AstRetrieve that owns this range.
		 * @param AstInterface $node Starting node
		 * @return AstRetrieve|null
		 */
		private function getParentRetrieve(AstInterface $node): ?AstRetrieve {
			$current = $node->getParent();
			
			while ($current !== null) {
				if ($current instanceof AstRetrieve) {
					return $current;
				}
				
				$current = $current->getParent();
			}
			
			return null;
		}
		
		/**
		 * Build the AST subtree for `<rangeAlias>.<columnName> = '<value>'`.
		 * @param string $rangeAlias The range alias (e.g. 't')
		 * @param string $columnName The discriminator column name (e.g. 'type')
		 * @param string $value      The discriminator value (e.g. 'truck')
		 * @return AstExpression
		 */
		private function buildDiscriminatorCondition(string $rangeAlias, string $columnName, string $value): AstExpression {
			// Build the left side: rangeAlias.columnName identifier chain
			$rangeIdentifier  = new AstIdentifier($rangeAlias);
			$columnIdentifier = new AstIdentifier($columnName);
			$rangeIdentifier->setNext($columnIdentifier);
			
			// Build the right side: the discriminator value as a string literal
			$valueNode = new AstString($value, "'");
			
			// Combine into an equality expression
			return new AstExpression($rangeIdentifier, $valueNode, '=');
		}
	}