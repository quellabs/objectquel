<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AddNamespacesToEntities
	 * Validates the existence of entities within an AST.
	 */
	class AddNamespacesToEntities implements AstVisitorInterface {
		
		/**
		 * The EntityStore for storing and fetching entity metadata.
		 * @var $entityStore EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * The macros used
		 */
		private array $macros;
		
		/**
		 * The ranges used
		 */
		private array $ranges;
		
		/**
		 * EntityExistenceValidator constructor.
		 * @param EntityStore $entityStore
		 * @param array $ranges
		 * @param array $macros
		 */
		public function __construct(EntityStore $entityStore, array $ranges, array $macros) {
			$this->entityStore = $entityStore;
			$this->ranges = $ranges;
			$this->macros = $macros;
		}
		
		/**
		 * Returns true if the given range exists, false if not
		 * @param string $name
		 * @return bool
		 */
		private function rangeExists(string $name): bool {
			foreach($this->ranges as $range) {
				if ($name == $range->getName()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns true if the given macro exists, false if not
		 * @param string $name
		 * @return bool
		 */
		private function macroExists(string $name): bool {
			return array_key_exists($name, $this->macros);
		}
		
		/**
		 * Function to visit a node in the AST (Abstract Syntax Tree).
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Checks if the node is an instance of AstEntity. If not, the function stops.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Checks that the node is not part of a chain
			if ($node->getParent() !== null) {
				return;
			}
			
			// Checks if there is a macro with the same name as the node.
			// If that's the case, the function stops without further action.
			if ($this->macroExists($node->getName())) {
				return;
			}
			
			// Checks if there is a range with the same name as the node.
			// Here too, if that's the case, the function stops without further action.
			if ($this->rangeExists($node->getName())) {
				return;
			}
			
			// If none of the above checks are true, the function adds a namespace
			// to the name of the node. This is done by a method of the entityStore object.
			$node->setName($this->entityStore->normalizeEntityName($node->getName()));
		}
	}