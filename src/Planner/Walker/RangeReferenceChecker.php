<?php
	
	namespace Quellabs\ObjectQuel\Planner\Walker;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSearch;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Returns true if any node in the walked subtree references a specific AstRange.
	 * Short-circuits as soon as a match is found: once the left child returns true,
	 * the right child is not visited.
	 *
	 * Used by ConditionAnalyzer::hasReferenceToRange() and its cached variant.
	 *
	 * @extends AstWalker<bool>
	 */
	class RangeReferenceChecker extends AstWalker {
		
		/**
		 * @param AstRange $range The specific range to search for throughout the AST subtree
		 */
		public function __construct(private readonly AstRange $range) {}
		
		/**
		 * Returns true if this identifier's range matches the target range by name.
		 * Name comparison is used rather than object identity to handle cases where
		 * the same logical range is represented by different AstRange instances.
		 * @param AstIdentifier $node The identifier node being visited
		 * @return bool True if this identifier belongs to the target range
		 */
		protected function visitIdentifier(AstIdentifier $node): bool {
			return $node->getRange()?->getName() === $this->range->getName();
		}
		
		/**
		 * Short-circuits: walks the right child only if the left child returned false.
		 * This avoids unnecessary traversal once a match has already been found.
		 * @param NodeBinary $node A node exposing getLeft() and getRight()
		 * @return bool True if either child subtree references the target range
		 */
		protected function visitBinary(NodeBinary $node): bool {
			return $this->walk($node->getLeft()) || $this->walk($node->getRight());
		}
		
		/**
		 * Short-circuits: stops iterating as soon as any identifier matches.
		 * Overrides the base fold to avoid visiting identifiers that cannot affect
		 * the result once a match has been found.
		 * @param NodeSearch $node A node exposing getIdentifiers()
		 * @return bool True if any of the search node's identifiers belong to the target range
		 */
		protected function visitSearch(NodeSearch $node): bool {
			foreach ($node->getIdentifiers() as $identifier) {
				if ($this->walk($identifier)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns false for literals, parameters, and any other terminal node type,
		 * since they carry no range reference.
		 * @param AstInterface $node The unrecognised or terminal node
		 * @return bool Always false
		 */
		protected function visitDefault(AstInterface $node): bool {
			return false;
		}
		
		/**
		 * Combines two boolean results with OR. Not called directly by visitBinary()
		 * or visitSearch() in this class (both short-circuit instead), but required
		 * by the abstract base and may be used by visitSingleExpression() via the
		 * inherited default.
		 * @param mixed $left bool result from the left child
		 * @param mixed $right bool result from the right child
		 * @return bool True if either side found a reference to the target range
		 */
		protected function mergeBinary(mixed $left, mixed $right): bool {
			return $left || $right;
		}
	}