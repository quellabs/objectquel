<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSingleExpression;
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
			
			// Already wrapped — skip to prevent double-wrapping.
			if ($node->getParent() instanceof AstDate) {
				return;
			}
			
			// Only act on datetime columns.
			if ($this->resolveType->inferReturnTypeOfIdentifier($node) !== '\DateTime') {
				return;
			}
			
			// Wrap the identifier with AstDate. foldedSeconds is null because this
			// is a column reference, not a pre-computed interval string.
			$wrapped = new AstDate($node, null);
			
			// Replace the identifier in its parent using the appropriate setter.
			$parent = $node->getParent();
			
			if ($parent instanceof NodeBinary) {
				if ($parent->getLeft() === $node) {
					$parent->setLeft($wrapped);
				} else {
					$parent->setRight($wrapped);
				}
			} elseif ($parent instanceof NodeSingleExpression) {
				$parent->setExpression($wrapped);
			}
			
			// Update the wrapped node's parent reference so further traversal
			// from ancestor nodes sees the correct tree structure.
			$wrapped->setParent($parent);
		}
	}