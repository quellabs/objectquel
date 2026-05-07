<?php
	
	namespace Quellabs\ObjectQuel\Planner\Walker;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSearch;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Returns true if any node in the walked subtree references any AstRange at all,
	 * regardless of which range. Short-circuits as soon as any range-bearing identifier
	 * is found.
	 *
	 * Used by ConditionAnalyzer::containsAnyRangeReference() to distinguish expressions
	 * that touch database data from those that can be evaluated in pure PHP.
	 *
	 * @extends AstWalker<bool>
	 */
	class AnyRangeReferenceChecker extends AstWalker {
		
		/**
		 * Returns true if this identifier has an associated range, meaning it represents
		 * a field from a database table or other data source rather than a literal value.
		 * @param AstIdentifier $node The identifier node being visited
		 * @return bool True if the identifier is bound to a range
		 */
		protected function visitIdentifier(AstIdentifier $node): bool {
			return $node->getRange() !== null;
		}
		
		/**
		 * Short-circuits: walks the right child only if the left child returned false.
		 * This avoids unnecessary traversal once a range reference has already been found.
		 * @param NodeBinary $node A node exposing getLeft() and getRight()
		 * @return bool True if either child subtree contains any range reference
		 */
		protected function visitBinary(NodeBinary $node): bool {
			return $this->walk($node->getLeft()) || $this->walk($node->getRight());
		}
		
		/**
		 * Short-circuits: stops iterating as soon as any identifier has a range.
		 * Overrides the base fold to avoid visiting identifiers that cannot affect
		 * the result once a match has been found.
		 * @param NodeSearch $node A node exposing getIdentifiers()
		 * @return bool True if any of the search node's identifiers are bound to a range
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
		 * @return bool True if either side found any range reference
		 */
		protected function mergeBinary(mixed $left, mixed $right): bool {
			return $left || $right;
		}
	}