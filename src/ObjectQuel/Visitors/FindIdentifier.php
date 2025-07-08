<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstFinderException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * FindIdentifier class
	 *
	 * This visitor class implements the visitor pattern to search for specific identifiers
	 * within an Abstract Syntax Tree (AST). It traverses the AST looking for an AstIdentifier
	 * node that matches both the provided entity name and property.
	 */
	class FindIdentifier implements AstVisitorInterface {
		
		/** @var string The entity name to search for */
		private string $entityName;
		
		/** @var string The property name to search for */
		private string $property;
		
		/** @var AstIdentifier|null The found identifier node */
		private ?AstIdentifier $result;
		
		/**
		 * FindIdentifier constructor
		 * @param string $entityName The entity name to match
		 * @param string $property The property name to match
		 */
		public function __construct(string $entityName, string $property) {
			$this->entityName = $entityName;
			$this->property = $property;
			$this->result = null;
		}
		
		/**
		 * Visits a node in the AST and checks if it matches the search criteria
		 *
		 * If the node is an AstIdentifier and matches both the entity name and property name,
		 * throw an AstFinderException with the found node. This exception is used as a
		 * mechanism to immediately stop traversal once a match is found.
		 * @param AstInterface $node The current node being visited
		 * @throws AstFinderException When a matching identifier is found
		 */
		public function visitNode(AstInterface $node): void {
			// Skip if not an AstIdentifier node
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip if entity name doesn't match
			if ($node->getEntityName() !== $this->entityName) {
				return;
			}
			
			// Skip if property name doesn't match
			if ($node->getName() !== $this->property) {
				return;
			}
			
			// If we reach here, we found a match - throw exception to halt traversal
			throw new AstFinderException("Found identifier", 0, null, $node);
		}
		
		/**
		 * Gets the found identifier result
		 * @return AstIdentifier|null The found identifier or null if not found
		 */
		public function getResult(): ?AstIdentifier {
			return $this->result;
		}
	}