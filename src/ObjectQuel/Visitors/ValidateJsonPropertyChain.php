<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Validates that JsonProperty nodes only appear after a JSON-typed EntityProperty.
	 *
	 * After ResolvePropertyType has typed every identifier node, this visitor
	 * checks two invariants for every JsonProperty node it encounters:
	 *
	 * 1. Its immediate parent must be either:
	 *    - An EntityProperty node whose column is JSON-typed (the boundary node), or
	 *    - Another JsonProperty node (deeper nesting inside the same JSON value).
	 *
	 * 2. A JsonProperty node may never have an EntityProperty child — once the chain
	 *    enters JSON territory it must stay there.
	 *
	 * These rules ensure queries like `a.nonJson.id` and `a.meta.id.b` (where b
	 * would be treated as an entity property again) are rejected at compile time
	 * rather than silently producing wrong SQL.
	 *
	 * This visitor must run after ResolvePropertyType so that all type assignments
	 * are already in place.
	 */
	class ValidateJsonPropertyChain implements AstVisitorInterface {
		
		/** @var EntityStore Used to look up column definitions for boundary detection. */
		private EntityStore $entityStore;
		
		/**
		 * @param EntityStore $entityStore Used to look up column definitions for boundary detection.
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visit a node and validate any JsonProperty invariants it carries.
		 * @param AstInterface $node The node currently being visited.
		 * @return void
		 * @throws SemanticException When a JsonProperty appears in an invalid position.
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes are relevant for this check.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only validate JsonProperty nodes; other types are handled elsewhere.
			if ($node->getType() !== IdentifierType::JsonProperty) {
				return;
			}
			
			// Invariant 1: the parent of a JsonProperty must be a JSON-typed
			// EntityProperty (the boundary) or another JsonProperty (deeper path).
			$parent = $node->getParent();
			
			if (!$parent instanceof AstIdentifier) {
				throw new SemanticException(
					"JSON property '{$node->getName()}' has no identifier parent. " .
					"JSON path access requires a JSON-typed entity column before the first path segment."
				);
			}
			
			$parentType = $parent->getType();
			
			if ($parentType === IdentifierType::EntityProperty) {
				// The parent must itself be a JSON-typed column.
				if (!$this->isJsonColumn($parent)) {
					throw new SemanticException(sprintf(
						"Property '%s' is not a JSON column and cannot be used for JSON path access. " .
						"Only properties mapped to a 'json' or 'jsonb' column support dot-access for JSON extraction.",
						$parent->getName()
					));
				}
			} elseif (
				$parentType !== IdentifierType::JsonProperty &&
				$parentType !== IdentifierType::JsonRoot
			) {
				// JsonRoot is a valid parent: it covers json_source() ranges where
				// every child is already a JSON path segment by definition.
				// Any other parent type (EntityRoot, SubqueryRoot, etc.) is invalid.
				throw new SemanticException(sprintf(
					"JSON property '%s' appears after an unexpected parent type. " .
					"JSON path segments must follow a JSON-typed entity column or another JSON path segment.",
					$node->getName()
				));
			}
		}
		
		/**
		 * Returns true when the given EntityProperty node maps to a JSON-typed column.
		 *
		 * Mirrors the same check in ResolvePropertyType so that validation is fully
		 * self-contained and does not depend on the tagging pass having run first
		 * (though in practice it always will have).
		 * @param AstIdentifier $propertyNode An AstIdentifier node typed EntityProperty.
		 * @return bool True if the column is JSON-typed, false otherwise.
		 * @throws EntityResolutionException
		 */
		private function isJsonColumn(AstIdentifier $propertyNode): bool {
			$entityName = $propertyNode->getBaseIdentifier()?->getEntityName();
			
			if ($entityName === null) {
				return false;
			}
			
			$metadata = $this->entityStore->getMetadata($entityName);
			$propertyName = $propertyNode->getName();
			$columnName = $metadata->columnMap[$propertyName] ?? null;
			
			if ($columnName === null) {
				return false;
			}
			
			$columnType = $metadata->columnDefinitions[$columnName]['type'] ?? null;
			return $columnType === 'json';
		}
	}