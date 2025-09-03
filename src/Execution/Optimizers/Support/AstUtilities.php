<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	
	/**
	 * AstUtilities provides common AST manipulation and inspection methods.
	 *
	 * This class contains pure utility functions for:
	 * - Combining predicates with AND operations
	 * - Checking for binary operators (AND/OR)
	 * - Collecting identifiers from AST subtrees
	 * - Getting children from binary operators
	 *
	 * All methods are stateless and can be used across different optimizer components.
	 */
	class AstUtilities {
		
		/**
		 * Build a left-associative AND chain from a list of predicates.
		 * Examples:
		 *   []        → null
		 *   [a]       → a
		 *   [a,b,c]   → ((a AND b) AND c)
		 *
		 * @param AstInterface[] $parts Predicates to AND together (nulls are ignored).
		 * @return AstInterface|null Combined predicate or null if no parts
		 */
		public function combinePredicatesWithAnd(array $parts): ?AstInterface {
			// Drop nulls/empties early to keep the tree lean.
			$parts = array_values(array_filter($parts));
			$n = count($parts);
			
			if ($n === 0) {
				return null;
			}
			
			if ($n === 1) {
				return $parts[0];
			}
			
			// Build a simple left-deep AND tree; balancing offers no real advantage here.
			$acc = new AstBinaryOperator($parts[0], $parts[1], 'AND');
			
			for ($i = 2; $i < $n; $i++) {
				$acc = new AstBinaryOperator($acc, $parts[$i], 'AND');
			}
			
			return $acc;
		}
		
		/**
		 * Check if node is a binary AND operator.
		 * @param AstInterface $node Node to check
		 * @return bool True if node is AND operator
		 */
		public function isBinaryAndOperator(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'AND';
		}
		
		/**
		 * Check if node is a binary OR operator.
		 * @param AstInterface $node Node to check
		 * @return bool True if node is OR operator
		 */
		public function isBinaryOrOperator(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'OR';
		}
		
		/**
		 * Return binary operator children when present; otherwise empty array.
		 * @param AstInterface $node Node to get children from
		 * @return AstInterface[] Child nodes (left and right for binary operators)
		 */
		public function getChildrenFromBinaryOperator(AstInterface $node): array {
			return $node instanceof AstBinaryOperator ? [$node->getLeft(), $node->getRight()] : [];
		}
		
		/**
		 * Collect all identifiers in an AST subtree.
		 * We use this to find which ranges are referenced by expressions.
		 * @param AstInterface|null $ast AST node to traverse
		 * @return array<int,AstIdentifier> Array of identifier nodes found
		 */
		public function collectIdentifiersFromAst(?AstInterface $ast): array {
			if ($ast === null) {
				return [];
			}
			
			$visitor = new CollectIdentifiers();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		
	}