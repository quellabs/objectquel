<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Defines the contract for AST visitor implementations.
	 */
	interface AstVisitorInterface {
		
		/**
		 * Visit an AST node and perform the visitor's operation on it.
		 * @param AstInterface $node The node being visited
		 */
		public function visitNode(AstInterface $node): void;
	}