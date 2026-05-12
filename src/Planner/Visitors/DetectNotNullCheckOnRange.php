<?php
	
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that detects IS NOT NULL checks on a specific range alias.
	 *
	 * Used during JSON join type inference: if the WHERE clause contains
	 * `j.field IS NOT NULL`, the user is asserting that the JSON side must
	 * contribute a match, which implies inner join semantics rather than
	 * the default left join (enrichment) behaviour.
	 *
	 * Only direct identifier targets are checked (e.g. `j.field IS NOT NULL`).
	 * IS NOT NULL on computed expressions or function calls is ignored because
	 * their range membership cannot be reliably determined here.
	 */
	class DetectNotNullCheckOnRange implements AstVisitorInterface {
		
		/**
		 * The range alias to watch for (e.g. "j" in `j.field IS NOT NULL`).
		 * @var string
		 */
		private string $rangeName;
		
		/**
		 * Set to true as soon as a matching IS NOT NULL node is found,
		 * allowing subsequent visitNode calls to short-circuit immediately.
		 * @var bool
		 */
		private bool $found = false;
		
		/**
		 * @param string $rangeName The range alias to detect IS NOT NULL checks on
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Visits a node and records whether it is an IS NOT NULL check on the target range.
		 * @param AstInterface $node The AST node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Short-circuit once a match is already recorded
			if ($this->found) {
				return;
			}
			
			// Only AstCheckNotNull nodes are relevant here; IS NULL (AstCheckNull)
			// means the user is testing for absence of a match, which is a left
			// join pattern and must not be reclassified as inner.
			if (!$node instanceof AstCheckNotNull) {
				return;
			}
			
			// Only direct identifier targets can be attributed to a specific range;
			// complex expressions (functions, arithmetic) are left unclassified.
			$expression = $node->getExpression();
			
			if (!$expression instanceof AstIdentifier) {
				return;
			}
			
			// Record a hit if the identifier belongs to our target range
			if ($expression->getRange()?->getName() === $this->rangeName) {
				$this->found = true;
			}
		}
		
		/**
		 * Returns true if an IS NOT NULL check on the target range was found.
		 * @return bool
		 */
		public function isFound(): bool {
			return $this->found;
		}
	}