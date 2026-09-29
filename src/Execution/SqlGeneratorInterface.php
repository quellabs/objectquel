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

		/**
		 * Visit a node in a predicate position (WHERE, ON, AND/OR/NOT operand) and return its SQL.
		 * @param AstInterface $condition The condition node
		 * @return string The SQL predicate
		 */
		public function visitConditionAndReturnSQL(AstInterface $condition): string;

		/**
		 * Visit a node in a value position (select list, assigned value, argument, comparison operand) and return its SQL.
		 * @param AstInterface $value The value node
		 * @return string The SQL value
		 */
		public function visitValueAndReturnSQL(AstInterface $value): string;
	}