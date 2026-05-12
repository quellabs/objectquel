<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for AST nodes that wrap exactly one inner expression.
	 *
	 * Implemented by unary operators, logical NOT, NULL checks, and value wrappers
	 * (AstNot, AstCheckNull, AstCheckNotNull, AstAlias, AstIfNull).
	 * Walkers use this interface to recurse into the wrapped expression without
	 * enumerating every concrete wrapper type.
	 *
	 * For walkers that also need to reconstruct condition wrappers (NOT, IS NULL,
	 * IS NOT NULL) after filtering, use NodeConditionWrapper instead — it additionally
	 * declares setExpression() and is limited to nodes that appear in condition trees.
	 */
	interface NodeSingleExpression extends AstInterface {
		
		/**
		 * Returns the single inner expression wrapped by this node.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface;
	}