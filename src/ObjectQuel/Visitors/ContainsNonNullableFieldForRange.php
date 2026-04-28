<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks if a range references any non-nullable fields in the AST.
	 * Used to determine if a LEFT JOIN can be safely converted to an INNER JOIN.
	 */
	class ContainsNonNullableFieldForRange implements AstVisitorInterface {
		
		/** @var string Name of the range */
		private string $rangeName;
		
		/** @var EntityStore EntityStore reference */
		private EntityStore $entityStore;
		
		/** @var bool True once a non-nullable field reference has been found */
		private bool $nonNullableFound = false;
		
		/**
		 * Constructor
		 * @param string $rangeName
		 * @param EntityStore $entityStore
		 */
		public function __construct(string $rangeName, EntityStore $entityStore) {
			$this->rangeName = $rangeName;
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visits a node and records whether it is a non-nullable field reference for the target range.
		 * @param AstInterface $node
		 */
		public function visitNode(AstInterface $node): void {
			// Short-circuit once a match is already recorded
			if ($this->nonNullableFound) {
				return;
			}
			
			// Skip if not an identifier
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip if the node doesn't have a range
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip if there's no field name component
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
			
			// Record if this column is non-nullable
			if (isset($columnDefinitions[$columnName]) && !$columnDefinitions[$columnName]['nullable']) {
				$this->nonNullableFound = true;
			}
		}
		
		/**
		 * Returns true if a non-nullable field reference was found during traversal.
		 */
		public function isNonNullable(): bool {
			return $this->nonNullableFound;
		}
	}