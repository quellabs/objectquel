<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	interface SqlGeneratorInterface extends AstVisitorInterface {
		
		/**
		 * Visit an AST node and return its SQL representation.
		 * @param AstInterface $node The node being visited
		 * @return string The SQL representation of the node
		 */
		public function visitNodeAndReturnSQL(AstInterface $node): string;
	}