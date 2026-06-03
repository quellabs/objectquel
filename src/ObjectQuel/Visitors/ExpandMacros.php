<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	
	/**
	 * Expands alias references in WHERE / ORDER BY / other clauses by substituting
	 * the corresponding expression from the SELECT projection list.
	 *
	 * When a query aliases an expression in the SELECT list — e.g. t = sum(o.id) —
	 * and then references that alias elsewhere — e.g. where t > 10 — the bare
	 * identifier 't' must be replaced with a deep clone of sum(o.id) before SQL
	 * generation. This visitor performs that substitution in a single AST pass.
	 *
	 * The macro index (AstRetrieve::$macros) has been removed; this visitor looks
	 * up the projection list directly via AstRetrieve::getValueExpression(), which
	 * eliminates the double-ownership and double-traversal bugs that the old system
	 * introduced.
	 */
	class ExpandMacros implements AstVisitorInterface {
		
		/** @var AstRetrieve The query whose projection list is used for lookups */
		private AstRetrieve $retrieve;
		
		/**
		 * @param AstRetrieve $retrieve The query node whose SELECT list is searched
		 */
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
		}
		
		/**
		 * Visit a node in the AST.
		 * If the node is a bare identifier that matches a SELECT alias, replace it
		 * with a deep clone of the aliased expression.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Only bare root identifiers are candidates for alias expansion
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only act on root identifiers (not property segments inside a chain)
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// A chained identifier like 'a.b' is a range.property reference, not an alias
			if ($node->getNext() !== null) {
				return;
			}
			
			$name = $node->getName();
			
			// Look up the alias in the SELECT projection list
			$expression = $this->retrieve->getValueExpression($name);
			
			if ($expression === null) {
				return;
			}
			
			// A parent is required to graft the replacement into the tree
			$parent = $node->getParent();
			
			if ($parent === null) {
				return;
			}
			
			// Deep-clone so the substituted expression is independent of the
			// original in the SELECT list — each reference gets its own copy
			AstNodeReplacer::replaceChild($parent, $node, $expression->deepClone());
		}
	}