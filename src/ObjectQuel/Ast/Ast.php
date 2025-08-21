<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class Ast
	 * Abstract Syntax Tree (AST) Node base class.
	 * Serves as the base class for all specific AST nodes.
	 */
	abstract class Ast implements AstInterface {
		
		private ?AstInterface $parent = null;
		
		/**
		 * Accepts a visitor to perform operations on this node.
		 * The method delegates the call to the visitor, allowing it to
		 * perform some action on the node.
		 * @param AstVisitorInterface $visitor The visitor performing operations on the AST.
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			$visitor->visitNode($this);
		}
		
		/**
		 * Returns the return type of the AST
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return null;
		}
		
		/**
		 * Returns true if the node has a parent, false if not
		 * @return bool
		 */
		public function hasParent(): bool {
			return
				$this->parent !== null &&
				!is_a($this->parent, AstRetrieve::class);
		}
		
		/**
		 * Returns the parent Ast
		 * @return AstInterface|null
		 */
		public function getParent(): ?AstInterface {
			return $this->parent;
		}
		
		/**
		 * Sets a new parent Ast
		 * @param AstInterface|null $parent
		 * @return void
		 */
		public function setParent(?AstInterface $parent): void {
			$this->parent = $parent;
		}
		
		/**
		 * Creates a deep clone of this AST node.
		 * @return static A deep clone of this AST node
		 */
		abstract public function deepClone(): static;
		
		/**
		 * Helper method for cloning arrays of AST nodes
		 * @param array $array Array potentially containing AST nodes
		 * @param Ast|null $newParent The parent for cloned nodes
		 * @return array Cloned array
		 */
		protected function cloneArray(array $array, ?Ast $newParent = null): array {
			$cloned = [];

			foreach ($array as $key => $item) {
				if ($item instanceof AstInterface) {
					$clonedItem = $item->deepClone();
					$clonedItem->setParent($newParent);
					$cloned[$key] = $clonedItem;
				} else {
					$cloned[$key] = $item;
				}
			}
			
			return $cloned;
		}
		
		/**
		 * Helper method for cloning single AST nodes
		 * @param AstInterface|null $node Node to clone
		 * @param Ast|null $newParent The parent for the cloned node
		 * @return AstInterface|null Cloned node or null
		 */
		protected function cloneNode(?AstInterface $node, ?Ast $newParent = null): ?AstInterface {
			if ($node === null) {
				return null;
			}
			
			$cloned = $node->deepClone();
			$cloned->setParent($newParent);
			return $cloned;
		}
	}