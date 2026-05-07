<?php
	
	namespace Quellabs\ObjectQuel\Planner\Walker;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Base class for AST walkers that traverse the ObjectQuel AST without
	 * requiring accept() methods on the node classes themselves.
	 *
	 * Structural traversal lives here once. Concrete walkers override only the
	 * visit methods they care about and provide a merge strategy for binary nodes.
	 *
	 * Recursion safety:
	 *   Assumes the AST is acyclic, which is guaranteed by the parser.
	 *   No depth guard is therefore needed.
	 *
	 * @template T The return type produced and consumed by this walker.
	 */
	abstract class AstWalker {
		
		/**
		 * Dispatches a node to the appropriate visit method and returns the walker's
		 * result of type T. This is the single entry point for all traversal — both
		 * external callers and recursive calls within visit methods use this.
		 *
		 * Dispatch order matters: more specific types are checked before more general
		 * ones to ensure the correct visit method is called for every node type.
		 * @param AstInterface $node The AST node to dispatch
		 * @return mixed Result of type T
		 */
		public function walk(AstInterface $node): mixed {
			// Leaf: identifier (column reference, e.g. x.id)
			if ($node instanceof AstIdentifier) {
				return $this->visitIdentifier($node);
			}
			
			// Binary structural nodes: comparisons, logical operators, arithmetic.
			// All expose getLeft() / getRight().
			if ($node instanceof AstExpression ||
				$node instanceof AstBinaryOperator ||
				$node instanceof AstTerm ||
				$node instanceof AstFactor) {
				return $this->visitBinary($node);
			}
			
			// Unary wrapper: NOT, IS NULL, etc. Exposes getExpression().
			if ($node instanceof AstUnaryOperation) {
				return $this->visitUnary($node->getExpression());
			}
			
			// Single-expression wrappers: alias and IFNULL both expose getExpression().
			if ($node instanceof AstAlias || $node instanceof AstIfNull) {
				return $this->walk($node->getExpression());
			}
			
			// Aggregate functions: COUNT, COUNT UNIQUE, AVG, AVG UNIQUE, MAX, MIN,
			// SUM, SUM UNIQUE, ANY — all expose getIdentifier().
			if ($node instanceof AstCount ||
				$node instanceof AstCountU ||
				$node instanceof AstAvg ||
				$node instanceof AstAvgU ||
				$node instanceof AstMax ||
				$node instanceof AstMin ||
				$node instanceof AstSum ||
				$node instanceof AstSumU ||
				$node instanceof AstAny) {
				return $this->walk($node->getIdentifier());
			}
			
			// Type-check functions: IS NUMERIC, IS FLOAT, IS INTEGER, IS EMPTY —
			// all expose getValue().
			if ($node instanceof AstIsNumeric ||
				$node instanceof AstIsFloat ||
				$node instanceof AstIsInteger ||
				$node instanceof AstIsEmpty) {
				return $this->walk($node->getValue());
			}
			
			// Full-text search nodes expose getIdentifiers() — a list of AstIdentifier nodes.
			if ($node instanceof AstSearch || $node instanceof AstSearchScore) {
				return $this->visitSearch($node);
			}
			
			// Literals, parameters, and any node type not listed above.
			return $this->visitDefault($node);
		}
		
		// =========================================================================
		// Override points
		// =========================================================================
		
		/**
		 * Called for AstIdentifier leaf nodes — column references such as x.id.
		 * The default implementation delegates to visitDefault().
		 * Override to inspect or collect data from identifier nodes.
		 * @param AstIdentifier $node The identifier node being visited
		 * @return mixed Result of type T
		 */
		protected function visitIdentifier(AstIdentifier $node): mixed {
			return $this->visitDefault($node);
		}
		
		/**
		 * Called for binary nodes: AstExpression, AstBinaryOperator, AstTerm, AstFactor.
		 * All of these expose getLeft() and getRight().
		 *
		 * The default implementation recurses into both children unconditionally and
		 * combines the results via mergeBinary(). Override to implement short-circuit
		 * behaviour — for example, a boolean OR walk can skip the right child as soon
		 * as the left child returns true.
		 * @param AstInterface $node A binary node exposing getLeft() and getRight()
		 * @return mixed Result of type T
		 */
		protected function visitBinary(AstInterface $node): mixed {
			return $this->mergeBinary(
				$this->walk($node->getLeft()),
				$this->walk($node->getRight())
			);
		}
		
		/**
		 * Called for AstUnaryOperation nodes. The inner expression is already unwrapped
		 * by walk() before this method is invoked, so implementations receive the child
		 * node directly rather than the unary wrapper.
		 *
		 * The default implementation walks the inner expression and returns its result.
		 * Override when the unary operator itself is significant to the walk (e.g. NOT
		 * negation in a boolean context).
		 * @param AstInterface $inner The expression wrapped by the unary operator
		 * @return mixed Result of type T
		 */
		protected function visitUnary(AstInterface $inner): mixed {
			return $this->walk($inner);
		}
		
		/**
		 * Called for AstSearch and AstSearchScore nodes. Both expose getIdentifiers(),
		 * which returns a list of AstIdentifier nodes representing the searched columns.
		 *
		 * The default implementation walks each identifier in order, folding results
		 * pairwise through mergeBinary(). The initial accumulator is visitDefault($node),
		 * so an empty identifier list returns the walker's zero value.
		 *
		 * Override when you need early-exit behaviour — for example, a boolean checker
		 * can return true as soon as the first matching identifier is found rather than
		 * visiting the full list.
		 * @param AstSearch|AstSearchScore $node The full-text search node being visited
		 * @return mixed Result of type T
		 */
		protected function visitSearch(AstSearch|AstSearchScore $node): mixed {
			$result = $this->visitDefault($node);
			
			foreach ($node->getIdentifiers() as $identifier) {
				$result = $this->mergeBinary($result, $this->walk($identifier));
			}
			
			return $result;
		}
		
		/**
		 * Fallback handler for any node type not explicitly dispatched by walk().
		 * This covers literals (integers, strings, booleans), bind parameters, and
		 * any future node types not yet added to the dispatch table.
		 *
		 * The default implementation returns null, which serves as the zero/identity
		 * value for most walker result types. Concrete walkers should override this
		 * to return the appropriate zero for their type T (e.g. false for bool walkers,
		 * [] for array collectors).
		 * @param AstInterface $node The unrecognised or terminal node
		 * @return mixed Result of type T — default is null
		 */
		protected function visitDefault(AstInterface $node): mixed {
			return null;
		}
		
		/**
		 * Combines the results from the left and right children of a binary node.
		 * Also used by the default visitSearch() implementation to fold over multiple
		 * identifiers.
		 *
		 * Every concrete walker must implement this to define how partial results
		 * are combined. Examples: boolean OR for checkers, array_merge for collectors.
		 *
		 * This method must be pure — it should not produce side effects or modify
		 * either argument.
		 * @param mixed $left Result of type T from the left child (or prior accumulator)
		 * @param mixed $right Result of type T from the right child (or current item)
		 * @return mixed Combined result of type T
		 */
		abstract protected function mergeBinary(mixed $left, mixed $right): mixed;
	}