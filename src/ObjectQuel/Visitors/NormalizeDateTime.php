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
	 * Visits terminal AstIdentifier nodes (those with no next in the chain —
	 * i.e. the actual column node, e.g. "createdAt" in "p.createdAt"). When
	 * the column resolves to \DateTime and appears inside a binary expression,
	 * the root of the chain is wrapped with AstDate so that handleDate emits
	 * the correct UNIX_TIMESTAMP() SQL.
	 *
	 * Double-wrapping is prevented by checking whether the chain root's parent
	 * is already an AstDate.
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
			
			// Only act on terminal nodes — the actual column at the end of the chain.
			// "createdAt" in "p.createdAt" has no next; "p" does. Skip non-terminals.
			if ($node->hasNext()) {
				return;
			}
			
			// Only act on datetime columns.
			if ($this->resolveType->inferReturnTypeOfIdentifier($node) !== '\DateTime') {
				return;
			}
			
			// Walk up to the root of the chain — that's the node to wrap.
			// For "p.createdAt": terminal is "createdAt", root is "p".
			$root = $node;

			while ($root->hasParentIdentifier()) {
				$parent = $root->getParent();

				if (!$parent instanceof AstIdentifier) {
					break;
				}

				$root = $parent;
			}
			
			// Only wrap when the root appears directly inside a binary expression.
			// Projections and aliases are left untouched — the hydrator handles
			// those correctly via @Column annotations.
			$binaryParent = $root->getParent();
			
			// Already wrapped — skip to prevent double-wrapping.
			if ($binaryParent instanceof AstDate) {
				return;
			}
			
			// Do nothing if identifier is not part of expression
			if (!$binaryParent instanceof NodeBinary) {
				return;
			}
			
			// Wrap the root with AstDate. handleDate will emit UNIX_TIMESTAMP(col).
			// Capture the parent before constructing AstDate — the constructor calls
			// $root->setParent($this) which would overwrite the original parent reference.
			$wrapped = new AstDate($root, null);

			// Wire the wrapped node into the binary expression.
			if ($binaryParent->getLeft() === $root) {
				$binaryParent->setLeft($wrapped);
			} else {
				$binaryParent->setRight($wrapped);
			}
		}
	}