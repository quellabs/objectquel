<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NodeTypeValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNodeObject;
	
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
			return true;
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
		 * Returns the parent AST path from root to the immediate parent of this node.
		 * The path is built by walking up the parent chain and is ordered from
		 * root (index 0) to immediate parent (last index).
		 * @return AstInterface[]
		 */
		public function getParentPath(): array {
			$path = [];
			$current = $this->parent;
			
			while ($current !== null) {
				array_unshift($path, $current);
				$current = $current->getParent();
			}
			
			return $path;
		}
		
		/**
		 * Returns true if one the parents is $className
		 * @param string $className
		 * @return bool
		 */
		public function parentContains(string $className): bool {
			$current = $this->parent;
			
			while ($current !== null) {
				if (is_a($current, $className)) {
					return true;
				}
				
				$current = $current->getParent();
			}
			
			return false;
		}
		
		/**
		 * Returns true if one the parents is $className
		 * @param array $classNames
		 * @return bool
		 */
		public function parentIsOneOf(array $classNames): bool {
			$current = $this->parent;
			
			while ($current !== null) {
				foreach ($classNames as $className) {
					if (is_a($current, $className)) {
						return true;
					}
				}
				
				$current = $current->getParent();
			}
			
			return false;
		}
		
		/**
		 * Determines if this node is an ancestor of the given node by checking
		 * if this node appears anywhere in the given node's AST subtree.
		 * Uses exception-based control flow for early termination when match is found.
		 * @param AstInterface $node The potential descendant node to check
		 * @return bool True if this node is an ancestor of the given node, false otherwise
		 */
		public function isAncestorOf(AstInterface $node): bool {
			try {
				// Create a visitor that searches for this node within the given node's subtree
				$visitor = new ContainsNodeObject($this);
				
				// Traverse the given node's AST - if our node is found as a descendant,
				// the visitor will throw an exception to signal the match
				$node->accept($visitor);
				
				// If we reach here, the visitor completed without finding our node,
				// meaning this node is NOT an ancestor of the given node
				return false;
			} catch (\Exception $exception) {
				// Exception thrown means the visitor found our node within the given node's subtree,
				// therefore this node IS an ancestor of the given node
				return true;
			}
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