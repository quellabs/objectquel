<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Normalizes bare datetime column references into AstDate nodes.
	 *
	 * ObjectQuel has a native datetime column type (via @Column(type="datetime")).
	 * When a datetime column is used in temporal arithmetic without an explicit
	 * date() wrapper, the SQL generator would emit the raw column name, producing
	 * a "Y-m-d H:i:s" string on the left side of an integer comparison — silently
	 * wrong.
	 *
	 * This visitor wraps any AstIdentifier whose resolved column type is \DateTime
	 * with an AstDate node, producing a canonical form where all temporal values
	 * are expressed as AstDate nodes regardless of whether the user wrote
	 * date(x.createdAt) or just x.createdAt.
	 *
	 * Double-wrapping is prevented by checking whether the parent of an identifier
	 * is already an AstDate — in that case the node is left unchanged.
	 *
	 * Replacement is performed via the parent node's setter (setLeft/setRight for
	 * NodeBinary, setExpression for NodeSingleExpression) using the parent reference
	 * that the AST maintains on every node.
	 */
	class NormalizeDateTime implements AstVisitorInterface {
		
		private ResolveType $resolveType;
		
		/**
		 * @param EntityStore $entityStore Used by ResolveType to look up column annotations
		 */
		public function __construct(EntityStore $entityStore) {
			$this->resolveType = new ResolveType($entityStore);
		}
		
		/**
		 * Visits an AST node. When the node is a bare datetime identifier whose
		 * parent is not already an AstDate, wraps it with AstDate via the parent's
		 * setter.
		 * @param AstInterface $node
		 * @return void
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only interested in identifiers.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only wrap the root of a property chain, not a chained property node.
			// p.createdAt is AstIdentifier("p") → AstIdentifier("createdAt").
			// Wrapping "createdAt" would break the chain since its parent must
			// remain an AstIdentifier. Wrapping the root "p" correctly produces
			// AstDate(AstIdentifier("p") → AstIdentifier("createdAt")), which
			// handleDate already handles for column references.
			if ($node->hasParentIdentifier()) {
				return;
			}
			
			// Only wrap datetime identifiers that appear inside arithmetic or
			// comparison expressions — i.e. when the parent is a NodeBinary.
			// Plain retrieve projections and top-level aliases are left untouched
			// since the hydrator already handles datetime columns correctly via
			// @Column annotations without any AstDate wrapping.
			if (!$node->getParent() instanceof NodeBinary) {
				return;
			}
			
			// Only act on datetime columns. The type lives on the last node in
			// the chain (e.g. "createdAt" in "p.createdAt"), not on the root
			// entity reference. Walk to the end of the chain to check.
			$last = $node;
			
			while ($last->hasNext()) {
				$next = $last->getNext();
				
				if ($next === null) {
					break;
				}
			}
			
			// Now check the return type
			if ($this->resolveType->inferReturnTypeOfIdentifier($last) !== '\DateTime') {
				return;
			}
			
			// Capture the parent before constructing AstDate — the constructor calls
			// $node->setParent($this) which would overwrite the original parent reference.
			$parent = $node->getParent();
			
			// Wrap the identifier with AstDate. foldedSeconds is null because this
			// is a column reference, not a pre-computed interval string.
			$wrapped = new AstDate($node, null);
			
			// Replace the identifier in its parent using the appropriate setter.
			if ($parent->getLeft() === $node) {
				$parent->setLeft($wrapped);
			} else {
				$parent->setRight($wrapped);
			}
		}
	}