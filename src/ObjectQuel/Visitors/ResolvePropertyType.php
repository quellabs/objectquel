<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Resolves the IdentifierType of every non-root node in an identifier chain.
	 *
	 * Root nodes are typed by ResolveRootIdentifierType. This visitor then walks
	 * every child (non-root) node and propagates the correct type downward, with
	 * one special case: when an EntityProperty node maps to a JSON-typed database
	 * column, its children are JsonProperty rather than EntityProperty, because
	 * further dot-access on a JSON column extracts a JSON path, not an ORM field.
	 *
	 * Example chains and the types assigned:
	 *   a (EntityRoot) → name (EntityProperty)
	 *   a (EntityRoot) → meta (EntityProperty/json) → id (JsonProperty)
	 *   a (EntityRoot) → meta (EntityProperty/json) → id (JsonProperty) → sub (JsonProperty)
	 *   s (SubqueryRoot) → col (SubqueryProperty)
	 *   j (JsonRoot) → field (JsonProperty)
	 */
	class ResolvePropertyType implements AstVisitorInterface {
		
		/** @var EntityStore Entity store used to look up column type metadata. */
		private EntityStore $entityStore;
		
		/**
		 * @param EntityStore $entityStore Entity store used to look up column type metadata.
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visit a node in the AST and assign its IdentifierType based on its parent's type.
		 *
		 * Only non-root AstIdentifier nodes (those whose parent is also an AstIdentifier)
		 * are processed. Root nodes are already typed by ResolveRootIdentifierType.
		 * @param AstInterface $node The node currently being visited.
		 * @return void
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes carry type information.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only act on non-root nodes: nodes whose parent is also an AstIdentifier.
			// Root nodes are already resolved by ResolveRootIdentifierType.
			$parentNode = $node->getParent();
			
			if (!$parentNode instanceof AstIdentifier) {
				return;
			}
			
			/** @noinspection PhpUncoveredEnumCasesInspection */
			switch ($parentNode->getType()) {
				case IdentifierType::EntityRoot:
					// Direct child of an entity root: always an entity property.
					$node->setType(IdentifierType::EntityProperty);
					break;
				
				case IdentifierType::EntityProperty:
					// Child of an entity property node. This is normally another entity
					// property (e.g. a.b.c where b is a relation), but when the parent
					// column is JSON-typed every further segment is a JSON path key.
					if ($this->isJsonColumn($parentNode)) {
						$node->setType(IdentifierType::JsonProperty);
					} else {
						$node->setType(IdentifierType::EntityProperty);
					}
					
					break;
				
				case IdentifierType::SubqueryRoot:
				case IdentifierType::SubqueryProperty:
					$node->setType(IdentifierType::SubqueryProperty);
					break;
				
				case IdentifierType::JsonRoot:
				case IdentifierType::JsonProperty:
					// Once inside a JSON path, all further segments are also JSON.
					$node->setType(IdentifierType::JsonProperty);
					break;
			}
		}
		
		/**
		 * Returns true when the given EntityProperty node maps to a JSON-typed column.
		 *
		 * Looks up the entity that owns this property via its base identifier, then
		 * checks the column definition's type field against the known JSON type strings
		 * ('json' and 'jsonb'). Returns false whenever the entity or column cannot be
		 * resolved so that callers always get a safe default.
		 * @param AstIdentifier $propertyNode An AstIdentifier node typed EntityProperty.
		 * @return bool True if the column is JSON-typed, false otherwise.
		 * @throws EntityResolutionException
		 */
		private function isJsonColumn(AstIdentifier $propertyNode): bool {
			// Walk up to the EntityRoot to obtain the entity class name.
			$entityName = $propertyNode->getBaseIdentifier()?->getEntityName();
			
			// Cannot determine the entity — treat as non-JSON to avoid false positives.
			if ($entityName === null) {
				return false;
			}
			
			// Look up the column type from entity metadata.
			$metadata = $this->entityStore->getMetadata($entityName);
			$propertyName = $propertyNode->getName();
			
			// Resolve property name → column name, then column name → definition.
			$columnName = $metadata->columnMap[$propertyName] ?? null;
			
			if ($columnName === null) {
				return false;
			}
			
			$columnType = $metadata->columnDefinitions[$columnName]['type'] ?? null;
			
			// Both 'json' (MySQL/MariaDB/SQLite) and 'jsonb' (PostgreSQL) are JSON.
			return $columnType === 'json';
		}
	}