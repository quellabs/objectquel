<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks if a range references any non-nullable fields in the AST.
	 * Used to determine if a LEFT JOIN can be safely converted to an INNER JOIN.
	 *
	 * Two resolution strategies are supported:
	 *   - Entity ranges:   nullability is read directly from entity metadata.
	 *   - Subquery ranges: nullability is resolved by tracing the subquery's output
	 *                      column back to its source field in entity metadata.
	 *
	 * Pass a $subquery to activate the subquery strategy; omit it (or pass null)
	 * for the direct entity strategy.
	 *
	 * @phpstan-import-type ColumnDefinitionRecord from EntityMetadataRecord
	 */
	class DetectNonNullableField implements AstVisitorInterface {
		
		/** @var string Name of the range to check references against */
		private string $rangeName;
		
		/** @var EntityStore Store for accessing entity metadata */
		private EntityStore $entityStore;
		
		/** @var AstRetrieve|null Subquery defining the range's output columns, or null for entity ranges */
		private ?AstRetrieve $subquery;
		
		/** @var bool True once a non-nullable field reference has been found */
		private bool $nonNullableFound = false;
		
		/**
		 * @param string $rangeName The range name to validate
		 * @param EntityStore $entityStore Store for entity metadata lookup
		 * @param AstRetrieve|null $subquery Subquery defining this range's columns, or null for direct entity ranges
		 */
		public function __construct(string $rangeName, EntityStore $entityStore, ?AstRetrieve $subquery = null) {
			$this->rangeName = $rangeName;
			$this->entityStore = $entityStore;
			$this->subquery = $subquery;
		}
		
		/**
		 * Visits a node and records whether it references a non-nullable field for the target range.
		 * @param AstInterface $node
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Short-circuit once a match is already recorded
			if ($this->nonNullableFound) {
				return;
			}
			
			// Only interested in base identifiers
			if (!$node instanceof AstIdentifier || !$node->isBaseIdentifier()) {
				return;
			}
			
			$range = $node->getRange();
			$next = $node->getNext();
			
			// Must have a range and a field component, and must match our target range
			if ($range === null || $next === null || $range->getName() !== $this->rangeName) {
				return;
			}

			// Check the correct part of the query
			if ($this->subquery !== null) {
				$this->checkSubqueryField($next->getName());
			} else {
				$this->checkEntityField($node->getEntityName(), $next->getName());
			}
		}
		
		/**
		 * Returns true if a non-nullable field reference was found during traversal.
		 */
		public function isNonNullable(): bool {
			return $this->nonNullableFound;
		}
		
		/**
		 * Resolves nullability for a direct entity range field.
		 * @param string|null $entityName
		 * @param string $fieldName
		 * @throws EntityResolutionException
		 */
		private function checkEntityField(?string $entityName, string $fieldName): void {
			if ($entityName === null) {
				return;
			}
			
			$metadata = $this->entityStore->getMetadata($entityName);
			$columnName = $metadata->columnMap[$fieldName] ?? $fieldName;
			
			if (
				isset($metadata->columnDefinitions[$columnName]) &&
				!$metadata->columnDefinitions[$columnName]['nullable']
			) {
				$this->nonNullableFound = true;
			}
		}
		
		/**
		 * Resolves nullability for a subquery range field by tracing the alias back to
		 * its source expression in the subquery's retrieve list.
		 * @param string $fieldName The aliased column name exposed by the subquery
		 * @throws EntityResolutionException
		 */
		private function checkSubqueryField(string $fieldName): void {
			$expression = $this->findExpressionByAlias($fieldName);
			
			if (
				$expression !== null &&
				$this->isExpressionNonNullable($expression)
			) {
				$this->nonNullableFound = true;
			}
		}
		
		/**
		 * Finds the expression in the subquery's retrieve list that produces the given alias.
		 * @param string $alias The field alias to search for
		 * @return AstInterface|null The expression producing this alias, or null if not found
		 */
		private function findExpressionByAlias(string $alias): ?AstInterface {
			if ($this->subquery !== null) {
				foreach ($this->subquery->getValues() as $astAlias) {
					if ($astAlias->getName() === $alias) {
						return $astAlias->getExpression();
					}
				}
			}
			
			return null;
		}
		
		/**
		 * Determines if an expression references a non-nullable field from an entity.
		 *
		 * Only checks direct field references (e.g., x.id). For computed expressions,
		 * functions, or references to nested subqueries, assumes nullable as a safe default.
		 *
		 * @param AstInterface $expression The expression to check
		 * @return bool True if the expression is a non-nullable field reference
		 * @throws EntityResolutionException
		 */
		private function isExpressionNonNullable(AstInterface $expression): bool {
			// Only direct field references (e.g. x.id) can be checked; computed
			// expressions, functions, and literals get the safe-default: nullable
			if (!$expression instanceof AstIdentifier) {
				return false;
			}
			
			// A valid field reference needs both a range (the "x" part) and a
			// next node (the "id" part); without either we can't look anything up
			if ($expression->getRange() === null || $expression->getNext() === null) {
				return false;
			}

			// Fetch expression data
			$rangeName = $expression->getRange()->getName();
			$fieldName = $expression->getNext()->getName();
			
			// Walk the subquery's range list to find the range this identifier belongs to;
			// we need the range to get the entity name for the metadata lookup below
			$sourceRange = null;
			
			if ($this->subquery !== null) {
				foreach ($this->subquery->getRanges() as $range) {
					if ($range->getName() === $rangeName) {
						$sourceRange = $range;
						break;
					}
				}
			}
			
			// Range not declared in the subquery — can't determine nullability
			if ($sourceRange === null) {
				return false;
			}
			
			// Don't recurse into nested subqueries: their output nullability depends
			// on their own structure, which we don't analyze here; assume nullable
			if ($sourceRange instanceof AstRangeDatabaseSubquery) {
				return false;
			}
			
			// No entity attached to this range (e.g. a raw table or unmapped source)
			$entityName = $sourceRange->getEntityName();
			
			if ($entityName === null || !$this->entityStore->exists($entityName)) {
				return false;
			}
			
			// Property not mapped — treat as nullable to avoid false positives
			$metadata = $this->entityStore->getMetadata($entityName);
			$annotations = $metadata->getAnnotations();
			
			if (!isset($annotations[$fieldName])) {
				return false;
			}
			
			// A Column annotation with nullable=false is the definitive signal;
			// any other annotation type on the same property is irrelevant here
			foreach ($annotations[$fieldName] as $annotation) {
				if ($annotation instanceof Column) {
					return !$annotation->isNullable();
				}
			}
			
			// No Column annotation found — can't confirm non-nullable
			return false;
		}
	}