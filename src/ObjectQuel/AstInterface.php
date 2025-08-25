<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Interface AstInterface
	 * Defines the contract for Abstract Syntax Tree (AST) nodes.
	 * All AST nodes must implement this interface to ensure consistent behavior
	 * across the AST structure.
	 */
	interface AstInterface {
		
		/**
		 * Accepts a visitor to perform operations on this node.
		 * The method delegates the call to the visitor, allowing it to
		 * perform some action on the node.
		 * @param AstVisitorInterface $visitor The visitor performing operations on the AST.
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void;
		
		/**
		 * Returns the return type of the AST node.
		 * This can be used for type checking and validation purposes.
		 * @return string|null The return type of the node, or null if not applicable
		 */
		public function getReturnType(): ?string;
		
		/**
		 * Returns true if the node has a parent, false if not.
		 * This is useful for traversing the AST structure.
		 * @return bool True if the node has a parent, false otherwise
		 */
		public function hasParent(): bool;
		
		/**
		 * Returns the parent AST node.
		 * @return AstInterface|null The parent node, or null if this is a root node
		 */
		public function getParent(): ?AstInterface;
		
		/**
		 * Sets a new parent AST node.
		 * @param AstInterface|null $parent The new parent node, or null to make this a root node
		 * @return void
		 */
		public function setParent(?AstInterface $parent): void;
		
		/**
		 * Make a deep clone of this node
		 * @return $this
		 */
		public function deepClone(): static;
	}