<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class ContainsRange
	 *
	 * This visitor class implements the visitor pattern to search for nodes that
	 * use a specific range within an Abstract Syntax Tree (AST).
	 * When a node with the specified range is found, it throws an exception
	 * to halt traversal and signal the presence of the range.
	 */
	class ContainsRange implements AstVisitorInterface {
		
		/** @var string The name of the range to search for */
		private string $rangeName;
		
		/**
		 * ContainsRange constructor.
		 * @param string $rangeName The name of the range to search for
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Visits a node in the AST (Abstract Syntax Tree) and checks if it uses the specified range.
		 *
		 * The method examines each node to determine if:
		 * 1. It is an AstIdentifier
		 * 2. It has a non-null range
		 * 3. The range name matches the one we're searching for
		 *
		 * If all conditions are met, an exception is thrown to indicate the range was found.
		 *
		 * @param AstInterface $node The current node being visited
		 * @return void
		 * @throws \Exception When a node with the specified range is found
		 */
		public function visitNode(AstInterface $node): void {
			// Skip if the node is not an AstIdentifier
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip if the node doesn't have a range
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip if the range name doesn't match the one we're looking for
			if ($node->getRange()->getName() !== $this->rangeName) {
				return;
			}
			
			// If we reach here, we found a match - throw exception to halt traversal
			throw new \Exception("Contains {$this->rangeName}");
		}
		
		/**
		 * Note: This visitor uses exceptions as a control flow mechanism.
		 * If no exception is thrown during traversal, it means the range was not found.
		 */
	}