<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Resolvers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	class ResolveRootIdentifierType implements AstVisitorInterface {
		
		/** @var AstRetrieve Query root node */
		private AstRetrieve $retrieve;
		
		/**
		 * ResolveEntityRootType
		 * @param AstRetrieve $retrieve
		 */
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
		}
		
		/**
		 * Function to visit a node in the AST (Abstract Syntax Tree).
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Checks if the node is an instance of AstIdentifier. If not, the function stops.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Check that this is the root of the chain
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// Lookup the node in the ranges list
			foreach($this->retrieve->getRanges() as $range) {
				// Name does not match. Next slot
				if ($node->getName() !== $range->getName()) {
					continue;
				}
				
				// Set correct type
				if ($range instanceof AstRangeDatabase) {
					$node->setType($node->getNext() !== null ? IdentifierType::EntityRoot : IdentifierType::EntityReference);
				} elseif ($range instanceof AstRangeDatabaseSubquery) {
					$node->setType(IdentifierType::SubqueryRoot);
				} elseif ($range instanceof AstRangeJsonSource) {
					$node->setType(IdentifierType::JsonRoot);
				}
			}
		}
	}