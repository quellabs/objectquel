<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AggregateCollector;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\IdentifierCollector;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NodeCollector;
	
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
		public static function combinePredicatesWithAnd(array $parts): ?AstInterface {
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
			$acc = AstFactory::createBinaryAndOperator($parts[0], $parts[1]);
			
			for ($i = 2; $i < $n; $i++) {
				$acc = AstFactory::createBinaryAndOperator($acc, $parts[$i]);
			}
			
			return $acc;
		}
		
		/**
		 * Check if node is a binary AND operator.
		 * @param AstInterface $node Node to check
		 * @return bool True if node is AND operator
		 */
		public static function isBinaryAndOperator(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'AND';
		}
		
		/**
		 * Check if node is a binary OR operator.
		 * @param AstInterface $node Node to check
		 * @return bool True if node is OR operator
		 */
		public static function isBinaryOrOperator(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'OR';
		}
		
		/**
		 * Collect all identifiers in an AST subtree.
		 * We use this to find which ranges are referenced by expressions.
		 * @param AstInterface|null $ast AST node to traverse
		 * @return array<int,AstIdentifier> Array of identifier nodes found
		 */
		public static function collectIdentifiersFromAst(?AstInterface $ast): array {
			if ($ast === null) {
				return [];
			}
			
			$visitor = new IdentifierCollector();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param AstRetrieve $root Query to visit
		 * @return AstInterface[] Aggregate nodes found in the tree
		 */
		public static function collectAggregateNodes(AstRetrieve $root): array {
			$visitor = new AggregateCollector(false);
			$root->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Collect all ANY nodes under the retrieve AST in one pass.
		 * @param AstRetrieve $ast Root query AST
		 * @return AstAny[] Array of ANY nodes found
		 */
		public static function findAllAnyNodes(AstRetrieve $ast): array {
			/** @var NodeCollector<AstAny> $visitor */
			$visitor = new NodeCollector([AstAny::class]);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Returns true if all projections are aggregates, false if not
		 * @param AstRetrieve $root
		 * @return bool
		 */
		public static function areAllSelectFieldsAggregates(AstRetrieve $root): bool {
			foreach ($root->getValues() as $value) {
				if (
					!$value->getExpression() instanceof AstAggregate ||
					$value->getExpression() instanceof AstAny
				) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Returns all query projections that are not aggregates
		 * @param AstRetrieve $root Query node
		 * @return array<int,mixed> Non-aggregate SELECT value nodes
		 */
		public static function collectNonAggregateSelectItems(AstRetrieve $root): array {
			$result = [];
			
			foreach ($root->getValues() as $selectItem) {
				if (
					!$selectItem->getExpression() instanceof AstAggregate ||
					$selectItem->getExpression() instanceof AstAny
				) {
					$result[] = $selectItem;
				}
			}
			
			return $result;
		}
	}