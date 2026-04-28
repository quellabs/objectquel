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
	 * performed on a specific range/table alias. This is used during JOIN optimization
	 * to determine whether a range has explicit null checks that prevent LEFT JOIN
	 * to INNER JOIN conversion.
	 *
	 * Implements the visitor pattern to traverse an Abstract Syntax Tree and
	 * records whether a null check operation on the specified range was found.
	 */
	class ContainsCheckIsNullForRange implements AstVisitorInterface {
		
		/**
		 * The name of the range/table alias to check for null operations
		 * @var string
		 */
		private string $rangeName;
		
		/** @var bool True once a null check on the target range has been found */
		private bool $found = false;
		
		/**
		 * ContainsCheckIsNullForRange constructor.
		 * @param string $rangeName The range/table alias name to monitor for null checks
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Visits a node in the AST and records if it's a null check on the target range.
		 * @param AstInterface $node The AST node to examine
		 */
		public function visitNode(AstInterface $node): void {
			// Short-circuit once a match is already recorded
			if ($this->found) {
				return;
			}
			
			// Early return if this isn't a null check node
			if (!$node instanceof AstCheckNull) {
				return;
			}
			
			// Early return if the null check expression isn't an identifier
			// (could be a complex expression, function call, etc.)
			if (!$node->getExpression() instanceof AstIdentifier) {
				return;
			}
			
			// Record if the identifier belongs to our target range
			if ($node->getExpression()->getRange()->getName() === $this->rangeName) {
				$this->found = true;
			}
		}
		
		/**
		 * Returns true if a null check on the target range was found during traversal.
		 * @return bool
		 */
		public function isFound(): bool {
			return $this->found;
		}
	}