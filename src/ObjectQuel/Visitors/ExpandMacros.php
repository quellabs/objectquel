<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Class EntityProcessRange
	 * If a given entity is a range, fetch the attached entity and
	 * store it in the AstEntity node.
	 */
	class ExpandMacros implements AstVisitorInterface {
		
		/**
		 * Array of macros
		 * @var array<string, AstInterface>
		 */
		private array $macros;
		
		/**
		 * EntityProcessMacro constructor.
		 * @param array<string, AstInterface> $macros
		 */
		public function __construct(array $macros) {
			$this->macros = $macros;
		}
		
		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Only handle identifiers
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// If the identifier is attached to an entity, this is definitely not a macro
			$entityName = $node->getEntityName();
			
			if ($entityName === null) {
				return;
			}
			
			// Check if the macro exists
			if (!array_key_exists($entityName, $this->macros)) {
				return;
			}
			
			// Check if the macro is an identifier of the correct type
			$macro = $this->macros[$entityName];
			
			if (
				!$macro instanceof AstIdentifier ||
				$macro->getType() !== IdentifierType::EntityReference
			) {
				return;
			}
			
			// Update the node with the macro contents
			$node->setName($macro->getName());
			$node->setRange($macro->getRange());
		}
	}