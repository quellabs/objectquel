<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks if a temporary range reference contains any non-nullable fields.
	 *
	 * Used during outer join validation to determine whether a LEFT JOIN to a subquery
	 * can be safely converted to an INNER JOIN. A non-nullable field in the join condition
	 * means the join can never produce a useful NULL row, making the conversion safe.
	 */
	class ContainsNonNullableFieldForRangeTemporary implements AstVisitorInterface {
		
		/** @var string The name of the temporary range to check references against */
		private string $rangeName;
		
		/** @var AstRetrieve The subquery that defines the temporary range's structure */
		private AstRetrieve $subquery;
		
		/** @var EntityStore Store for accessing entity metadata and annotations */
		private EntityStore $entityStore;
		
		/** @var bool True once a non-nullable field reference has been found */
		private bool $nonNullableFound = false;
		
		/**
		 * @param string $rangeName The temporary range name to validate
		 * @param AstRetrieve $subquery The subquery defining this range
		 * @param EntityStore $entityStore Store for entity metadata lookup
		 */
		public function __construct(string $rangeName, AstRetrieve $subquery, EntityStore $entityStore) {
			$this->rangeName = $rangeName;
			$this->subquery = $subquery;
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visits an AST node and records whether it references a non-nullable field
		 * from the target temporary range.
		 * @param mixed $node The AST node to visit
		 */
		public function visitNode($node): void {
			// Short-circuit once a match is already recorded
			if ($this->nonNullableFound) {
				return;
			}
			
			// Only interested in identifiers that reference our temporary range
			if (!($node instanceof AstIdentifier)) {
				return;
			}
			
			// Only handle base identifiers
			if (!($node->isBaseIdentifier())) {
				return;
			}
			
			// Check the range name
			if ($node->getRange()->getName() !== $this->rangeName) {
				return;
			}
			
			// Extract the field name being referenced (e.g., "id" from "temp.id")
			$fieldName = $node->getNext()->getName();
			
			// Find this field in the subquery's retrieve list
			$expression = $this->findExpressionByAlias($fieldName);
			
			if ($expression === null) {
				return; // Field not found in retrieve list
			}
			
			// Record if the retrieved expression is non-nullable
			if ($this->isExpressionNonNullable($expression)) {
				$this->nonNullableFound = true;
			}
		}
		
		/**
		 * Returns true if a non-nullable field reference was found during traversal.
		 */
		public function isNonNullable(): bool {
			return $this->nonNullableFound;
		}
		
		/**
		 * Finds the expression in the subquery's retrieve list that produces the given alias.
		 * @param string $alias The field alias to search for
		 * @return AstInterface|null The expression producing this alias, or null if not found
		 */
		private function findExpressionByAlias(string $alias): ?AstInterface {
			foreach ($this->subquery->getValues() as $astAlias) {
				if ($astAlias->getName() === $alias) {
					return $astAlias->getExpression();
				}
			}
			
			return null;
		}
		
		/**
		 * Determines if an expression references a non-nullable field from an entity.
		 *
		 * Only checks direct field references (e.g., x.id). For computed expressions, functions,
		 * or references to subqueries, assumes nullable as a safe default.
		 *
		 * @param AstInterface $expression The expression to check
		 * @return bool True if the expression is a non-nullable field reference
		 */
		private function isExpressionNonNullable(AstInterface $expression): bool {
			// Only check direct field references (e.g., x.id)
			// For computed expressions, functions, or subquery references, assume nullable (safe default)
			if (!$expression instanceof AstIdentifier) {
				return false;
			}
			
			$rangeName = $expression->getRange()->getName();
			$fieldName = $expression->getNext()->getName();
			
			// Find the source range in the subquery
			$sourceRange = null;
			foreach ($this->subquery->getRanges() as $range) {
				if ($range->getName() === $rangeName) {
					$sourceRange = $range;
					break;
				}
			}
			
			if ($sourceRange === null) {
				return false; // Can't determine, assume nullable
			}
			
			// Only analyze entity ranges, not nested subqueries
			if (!($sourceRange instanceof AstRangeDatabase) || $sourceRange->containsQuery()) {
				return false;
			}
			
			$entityName = $sourceRange->getEntityName();
			
			if ($entityName === null || !$this->entityStore->exists($entityName)) {
				return false; // No entity metadata available
			}
			
			// Get annotations for this property
			$annotations = $this->entityStore->getAnnotations($entityName);
			
			if (!isset($annotations[$fieldName])) {
				return false; // Property not found
			}
			
			// Check if the Column annotation marks this as non-nullable
			foreach ($annotations[$fieldName] as $annotation) {
				if ($annotation instanceof Column) {
					return !$annotation->isNullable();
				}
			}
			
			return false;
		}
	}