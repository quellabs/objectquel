<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for boolean condition nodes that wrap exactly one inner
	 * condition expression.
	 *
	 * Implemented by AstNot, AstCheckNull, and AstCheckNotNull. Walkers that filter
	 * or reconstruct condition trees use this interface to recurse into the inner
	 * condition and rebuild the wrapper without enumerating every concrete type.
	 *
	 * This is intentionally separate from NodeSingleExpression, which covers all
	 * single-child wrappers including non-condition nodes like AstAlias and AstIfNull.
	 * Only nodes that appear directly in a condition tree implement this interface.
	 */
	interface NodeConditionWrapper extends AstInterface {
		
		/**
		 * Returns the inner condition expression this node wraps.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface;
		
		/**
		 * Replaces the inner condition expression.
		 * Used by condition-tree walkers to reconstruct the wrapper after filtering
		 * the inner expression, without knowing the concrete wrapper type.
		 * @param AstInterface $expression
		 * @return void
		 */
		public function setExpression(AstInterface $expression): void;
	}