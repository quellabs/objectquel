<?php
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * This visitor implements the visitor pattern to traverse an AST and ensure that
	 * specific range nodes have appropriate ANY parent nodes in the hierarchy.
	 * Throws an exception if a matching range is found without the required ANY parent.
	 */
	class VisitorRangeNotInAggregates implements AstVisitorInterface {
		
		/**
		 * The specific range that this visitor is checking for
		 * @var AstRange
		 */
		private AstRange $targetRange;
		
		/**
		 * Constructor - initializes the visitor with the range to validate
		 * @param AstRange $range The range node that must have an ANY parent
		 */
		public function __construct(AstRange $range) {
			$this->targetRange = $range;
		}
		
		/**
		 * Visits each node in the AST and performs validation
		 * @param AstInterface $node The current AST node being visited
		 * @throws \Exception When a matching range is found without an ANY parent
		 */
		public function visitNode(AstInterface $node): void {
			// Only process identifier nodes
			if ($node instanceof AstIdentifier) {
				/** @var AstIdentifier $baseIdentifier */
				$baseIdentifier = $node->getBaseIdentifier();
				
				// Check if this identifier's range matches our target range
				// and verify it has the required ANY parent
				if (
					$baseIdentifier->getRange()->getName() === $this->targetRange->getName() &&
					!$this->hasAggregateParent($node)
				) {
					// Found non-ANY usage - throw exception to stop traversal
					throw new \Exception("Range used outside of ANY function");
				}
			}
		}
		
		/**
		 * This method walks up the parent chain from the given AST node
		 * to determine if there is an AstAny node somewhere in the ancestry.
		 * @param AstInterface $ast The starting node to check parents for
		 * @return bool True if an ANY parent is found, false otherwise
		 */
		private function hasAggregateParent(AstInterface $ast): bool {
			// Start with the immediate parent
			$parent = $ast->getParent();
			
			// If the parent is a range
			if ($parent instanceof AstRangeDatabase) {
				return true;
			}
			
			// Traverse up the parent chain
			while ($parent !== null) {
				// Check if current parent is an ANY node
				if (
					$parent instanceof AstAny ||
					$parent instanceof AstMin ||
					$parent instanceof AstMax ||
					$parent instanceof AstCount ||
					$parent instanceof AstCountU ||
					$parent instanceof AstAvg ||
					$parent instanceof AstAvgU ||
					$parent instanceof AstSum ||
					$parent instanceof AstSumU
				) {
					return true;
				}
				
				// Move to the next parent up the chain
				$parent = $parent->getParent();
			}
			
			// Reached root without finding any aggregation parent
			return false;
		}
	}