<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class ContainsCheckIsNullForRange
	 *
	 * AST visitor that detects when a null check (IS NULL/IS NOT NULL) is being
	 * performed on a specific range/table alias. This is used for query validation
	 * to prevent certain null checks on designated ranges.
	 *
	 * Implements the visitor pattern to traverse an Abstract Syntax Tree and
	 * throws an exception when it finds a null check operation on the specified range.
	 */
	class ContainsCheckIsNullForRange implements AstVisitorInterface {
		
		/**
		 * The name of the range/table alias to check for null operations
		 * @var string
		 */
		private string $rangeName;
		
		/**
		 * ContainsCheckIsNullForRange constructor.
		 * @param string $rangeName The range/table alias name to monitor for null checks
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Visits a node in the AST and checks if it's a null check on the target range.
		 * @param AstInterface $node The AST node to examine
		 * @return void
		 * @throws \Exception When a null check is found on the specified range
		 */
		public function visitNode(AstInterface $node): void {
			// Early return if this isn't a null check node
			if (!$node instanceof AstCheckNull) {
				return;
			}
			
			// Early return if the null check expression isn't an identifier
			// (could be a complex expression, function call, etc.)
			if (!$node->getExpression() instanceof AstIdentifier) {
				return;
			}
			
			// Check if the identifier belongs to our target range
			// If so, this is the prohibited null check we're looking for
			if ($node->getExpression()->getRange()->getName() === $this->rangeName) {
				throw new \Exception("Contains {$this->rangeName}");
			}
		}
	}