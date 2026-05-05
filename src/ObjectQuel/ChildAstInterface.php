<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Marks an AST node that can never be the tree root.
	 *
	 * Nodes implementing this interface always have a parent. The narrowed
	 * return type on getParent() lets callers (and static analysis) treat
	 * the result as a guaranteed AstInterface rather than ?AstInterface,
	 * eliminating defensive null-checks at every call site.
	 */
	interface ChildAstInterface extends AstInterface {
		
		/**
		 * Returns the parent node.
		 * Guaranteed non-null: a ChildAstInterface node always has a parent.
		 * @return AstInterface
		 */
		public function getParent(): AstInterface;
		
		/**
		 * Sets the parent node.
		 * Passing null is a logic error and must throw.
		 * @param AstInterface|null $parent
		 * @return void
		 */
		public function setParent(?AstInterface $parent): void;
	}