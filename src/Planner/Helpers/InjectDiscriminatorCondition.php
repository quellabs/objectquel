<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Metadata\DiscriminatorInfoResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
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
	class InjectDiscriminatorCondition {
		
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
		 * @throws TransformationException
		 * @throws QuelException If the range's entity declares STI annotations that are incomplete or empty
		 */
		public function process(AstRangeDatabase $range, AstRetrieve $retrieve): void {
			// Ranges backed by subqueries have no entity name — nothing to check
			$entityName = $range->getEntityName();
			
			// Valide the entity name
			if (empty($entityName)) {
				return;
			}
			
			if (!class_exists($entityName)) {
				return;
			}
			
			// Look up discriminator metadata for this entity. Returns null for the
			// common case where the entity is not an STI subclass, so we exit early
			// and add no overhead to ordinary queries.
			$discriminatorInfo = $this->getDiscriminatorInfo($entityName);
			
			if ($discriminatorInfo === null) {
				return;
			}
			
			// Build the AST node for `<alias>.<discriminatorColumn> = '<discriminatorValue>'`,
			// e.g. `t.type = 'truck'` for a TruckEntity range aliased as 't'
			$condition = $this->buildDiscriminatorCondition(
				$range->getName(),
				$discriminatorInfo['column'],
				$discriminatorInfo['value']
			);
			
			// The condition node must know its parent in the tree so that
			// ancestor-walking utilities (e.g. getLocationOfChild) work correctly
			$condition->setParent($retrieve);
			
			// Retrieve the current conditions
			$existingConditions = $retrieve->getConditions();
			
			// Set new conditions
			if ($existingConditions === null) {
				// No WHERE clause yet — the discriminator condition becomes the entire clause
				$retrieve->setConditions($condition);
			} else {
				// Prepend the discriminator condition so it appears at the start of the WHERE
				// clause. Putting it first lets the query optimizer use it as an early filter
				// before evaluating any user-supplied conditions.
				$combined = new AstBinaryOperator($condition, $existingConditions, 'AND');
				$combined->setParent($retrieve);
				$retrieve->setConditions($combined);
			}
		}
		
		/**
		 * Resolve discriminator metadata for a given entity class. Delegates
		 * to DiscriminatorInfoResolver, shared with InsertPersister and
		 * QuelToSQLAppend.
		 * @param class-string $entityName Fully qualified entity class name
		 * @return array{column: string, value: string}|null
		 * @throws TransformationException If annotation metadata cannot be read
		 * @throws QuelException If STI annotations are present but incomplete or contain empty values, indicating a misconfigured entity class
		 */
		private function getDiscriminatorInfo(string $entityName): ?array {
			try {
				return DiscriminatorInfoResolver::resolve($this->entityStore, $entityName);
			} catch (AnnotationReaderException $e) {
				throw new TransformationException($e->getMessage(), $e->getCode(), $e);
			}
		}
		
		/**
		 * Build the AST subtree for `<rangeAlias>.<columnName> = '<value>'`.
		 * @param string $rangeAlias The range alias (e.g. 't')
		 * @param string $columnName The discriminator column name (e.g. 'type')
		 * @param string $value The discriminator value (e.g. 'truck')
		 * @return AstExpression
		 */
		private function buildDiscriminatorCondition(string $rangeAlias, string $columnName, string $value): AstExpression {
			// Build the left-hand side as a chained identifier: rangeAlias.columnName
			// AstIdentifier chains represent dotted property access the same way
			// user-written expressions like `t.type` are represented in the AST
			$rangeIdentifier = new AstIdentifier($rangeAlias, IdentifierType::EntityRoot);
			$columnIdentifier = new AstIdentifier($columnName, IdentifierType::EntityProperty);
			$rangeIdentifier->setNext($columnIdentifier);
			
			// The right-hand side is a plain string literal for the discriminator value
			$valueNode = new AstString($value, "'");
			
			// Combine into an equality expression: <rangeAlias>.<columnName> = '<value>'
			return new AstExpression($rangeIdentifier, $valueNode, '=');
		}
	}