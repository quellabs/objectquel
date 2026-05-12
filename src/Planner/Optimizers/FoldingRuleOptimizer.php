<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Walks the WHERE clause and replaces AST nodes with AstBool constants wherever
	 * a registered FoldingRule can resolve them statically. Nodes that no rule
	 * recognises are left untouched.
	 *
	 * This pass only folds — it does not simplify the boolean constants it introduces.
	 * Run BooleanConstantOptimizer afterwards to collapse them through AND / OR / NOT.
	 *
	 * New folding behaviours are added by implementing FoldingRuleInterface and
	 * passing the rule to the constructor — no changes to this class are needed.
	 */
	class FoldingRuleOptimizer {
		
		/** @var FoldingRuleInterface[] Ordered list of rules tried against each node */
		private array $rules;
		
		/**
		 * @param FoldingRuleInterface[] $rules Rules to apply, tried in order until one matches
		 */
		public function __construct(array $rules) {
			$this->rules = $rules;
		}
		
		/**
		 * Walks the WHERE clause and replaces statically-resolvable nodes with boolean
		 * constants. No-ops when there are no conditions.
		 * @param AstRetrieve $ast The query to rewrite in-place
		 * @throws EntityResolutionException
		 */
		public function optimize(AstRetrieve $ast): void {
			$conditions = $ast->getConditions();
			
			if ($conditions === null) {
				return;
			}
			
			$ast->setConditions($this->fold($conditions));
		}
		
		/**
		 * Recursively attempts to fold each node using the registered rules.
		 * Structural nodes (NOT, AND/OR, comparisons) are recursed into so that
		 * type-check nodes nested anywhere in the tree are reached.
		 * Returns the (possibly rewritten) subtree.
		 * @param AstInterface $node The condition subtree to fold
		 * @return AstInterface The folded subtree
		 * @throws EntityResolutionException
		 */
		private function fold(AstInterface $node): AstInterface {
			// Try each rule against the current node; first match wins
			foreach ($this->rules as $rule) {
				$result = $rule->fold($node);
				
				if ($result !== null) {
					return $result;
				}
			}
			
			// Recurse into NOT — a foldable node may be nested, e.g. NOT is_float(x)
			if ($node instanceof AstNot) {
				$node->setExpression($this->fold($node->getExpression()));
				return $node;
			}
			
			// Recurse into AND / OR — either operand may contain a foldable node
			if ($node instanceof AstBinaryOperator) {
				$node->setLeft($this->fold($node->getLeft()));
				$node->setRight($this->fold($node->getRight()));
				return $node;
			}
			
			// Recurse into comparisons — handles patterns like is_float(...) = 0
			// where the foldable node is an operand of an = or <> expression.
			if ($node instanceof AstExpression) {
				$node->setLeft($this->fold($node->getLeft()));
				$node->setRight($this->fold($node->getRight()));
				return $node;
			}
			
			// Leaf node (identifier, literal, parameter, etc.) — nothing to fold
			return $node;
		}
	}