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
			return $this->parent !== null;
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
	}