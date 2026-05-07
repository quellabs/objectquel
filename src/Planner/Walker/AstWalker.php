<?php
	
	namespace Quellabs\ObjectQuel\Planner\Walker;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeFunction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSingleExpression;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Base class for AST walkers that traverse the ObjectQuel AST without
	 * requiring accept() methods on the node classes themselves.
	 *
	 * Structural traversal lives here once. Concrete walkers override only the
	 * visit methods they care about and provide a merge strategy for binary nodes.
	 *
	 * Node types are recognised via structural interfaces rather than concrete class
	 * names, so new AST node types automatically participate in traversal as long as
	 * they implement the appropriate interface — no changes to this class are needed.
	 *
	 * The structural interfaces and the node types that implement them are:
	 *   - NodeBinary           — AstExpression, AstBinaryOperator, AstTerm, AstFactor
	 *   - NodeSingleExpression — AstUnaryOperation, AstNot, AstCheckNull, AstCheckNotNull,
	 *                            AstAlias, AstIfNull
	 *   - NodeAggregate        — AstAggregate (base for COUNT, AVG, MAX, MIN, SUM, ANY, ...)
	 *   - NodeFunction         — AstIsNumeric, AstIsFloat, AstIsInteger, AstIsEmpty
	 *   - NodeSearch           — AstSearch, AstSearchScore
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
		 * @param AstInterface $node The AST node to dispatch
		 * @return mixed Result of type T
		 */
		public function walk(AstInterface $node): mixed {
			// Leaf: identifier (column reference, e.g. x.id). Checked first because
			// AstIdentifier does not implement any structural interface.
			if ($node instanceof AstIdentifier) {
				return $this->visitIdentifier($node);
			}
			
			// Binary nodes expose getLeft() and getRight().
			if ($node instanceof NodeBinary) {
				return $this->visitBinary($node);
			}
			
			// Single-expression wrappers expose getExpression().
			if ($node instanceof NodeSingleExpression) {
				return $this->visitSingleExpression($node);
			}
			
			// Aggregate functions expose getIdentifier().
			if ($node instanceof NodeAggregate) {
				return $this->walk($node->getIdentifier());
			}
			
			// Type-check functions expose getValue().
			if ($node instanceof NodeFunction) {
				return $this->walk($node->getValue());
			}
			
			// Full-text search nodes expose getIdentifiers().
			if ($node instanceof NodeSearch) {
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
		 * Called for NodeBinary implementations (AstExpression, AstBinaryOperator,
		 * AstTerm, AstFactor). All expose getLeft() and getRight().
		 *
		 * The default implementation recurses into both children unconditionally and
		 * combines the results via mergeBinary(). Override to implement short-circuit
		 * behaviour — for example, a boolean OR walk can skip the right child as soon
		 * as the left child returns true.
		 * @param NodeBinary $node A node exposing getLeft() and getRight()
		 * @return mixed Result of type T
		 */
		protected function visitBinary(NodeBinary $node): mixed {
			return $this->mergeBinary(
				$this->walk($node->getLeft()),
				$this->walk($node->getRight())
			);
		}
		
		/**
		 * Called for NodeSingleExpression implementations (AstUnaryOperation, AstNot,
		 * AstCheckNull, AstCheckNotNull, AstAlias, AstIfNull). All expose getExpression().
		 *
		 * The default implementation walks the inner expression and returns its result.
		 * Override when the wrapper node itself is significant to the walk (e.g. logical
		 * NOT inversion in a boolean context).
		 * @param NodeSingleExpression $node A node exposing getExpression()
		 * @return mixed Result of type T
		 */
		protected function visitSingleExpression(NodeSingleExpression $node): mixed {
			return $this->walk($node->getExpression());
		}
		
		/**
		 * Called for NodeSearch implementations (AstSearch, AstSearchScore). Both
		 * expose getIdentifiers(), which returns a list of column identifier nodes.
		 *
		 * The default implementation walks each identifier in order, folding results
		 * pairwise through mergeBinary(). The initial accumulator is visitDefault($node),
		 * so an empty identifier list returns the walker's zero value.
		 *
		 * Override when you need early-exit behaviour — for example, a boolean checker
		 * can return true as soon as the first matching identifier is found rather than
		 * visiting the full list.
		 * @param NodeSearch $node A node exposing getIdentifiers()
		 * @return mixed Result of type T
		 */
		protected function visitSearch(NodeSearch $node): mixed {
			$result = $this->visitDefault($node);
			
			foreach ($node->getIdentifiers() as $identifier) {
				$result = $this->mergeBinary($result, $this->walk($identifier));
			}
			
			return $result;
		}
		
		/**
		 * Fallback handler for any node type not matched by the structural interfaces.
		 * This covers literals (integers, strings, booleans), bind parameters, and
		 * any future node types that have not yet been assigned a structural interface.
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