<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * A single constant-folding rule for use with ConstantFoldingOptimizer.
	 *
	 * Each rule is responsible for recognising one category of AST node and
	 * determining whether it can be resolved to a boolean constant at compile
	 * time. Rules that cannot resolve a node return null, leaving it untouched
	 * for runtime evaluation.
	 */
	interface FoldingRuleInterface {
		
		/**
		 * Attempts to fold $node to a boolean constant.
		 * Returns null when the node cannot be resolved statically.
		 * @param AstInterface $node The node to examine
		 * @return AstBool|null The folded constant, or null if unresolvable
		 * @throws EntityResolutionException
		 */
		public function fold(AstInterface $node): ?AstBool;
	}