<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Throws an exception if the given range uses any non-nullable fields in the AST.
	 * This is used to determine if a LEFT JOIN can be safely converted to INNER JOIN.
	 */
	class ContainsNonNullableFieldForRange implements AstVisitorInterface {
		
		private string $rangeName;
		private EntityStore $entityStore;
		
		public function __construct(string $rangeName, EntityStore $entityStore) {
			$this->rangeName = $rangeName;
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visits a node and checks if it's a non-nullable field reference for the target range.
		 * @param AstInterface $node
		 * @return void
		 * @throws \Exception When a non-nullable field reference is found
		 */
		public function visitNode(AstInterface $node): void {
			// Skip if not an identifier
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip if no range
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip if no next
			if ($node->getNext() === null) {
				return;
			}
			
			// Skip if not our target range
			if ($node->getRange()->getName() !== $this->rangeName) {
				return;
			}
			
			// Get the entity name and field name
			$entityName = $node->getEntityName();
			$fieldName = $node->getNext()->getName();
			
			if ($entityName === null) {
				return;
			}
			
			// Get column definitions for this entity
			$columnDefinitions = $this->entityStore->extractEntityColumnDefinitions($entityName);
			
			// Get the column map to find the actual column name
			$columnMap = $this->entityStore->getColumnMap($entityName);
			$columnName = $columnMap[$fieldName] ?? $fieldName;
			
			// Check if this column is non-nullable
			if (isset($columnDefinitions[$columnName]) && !$columnDefinitions[$columnName]['nullable']) {
				throw new \Exception("Contains non-nullable field {$this->rangeName}.{$fieldName}");
			}
		}
	}