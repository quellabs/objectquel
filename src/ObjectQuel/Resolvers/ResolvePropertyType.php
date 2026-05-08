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
	
	class ResolvePropertyType implements AstVisitorInterface {
		
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
			
			// Check that this is the middle of the chain
			$parentNode = $node->getParent();
			if (!$parentNode instanceof AstIdentifier) {
				return;
			}
			
			// Lookup the node in the ranges list and set type
			/** @noinspection PhpUncoveredEnumCasesInspection */
			switch ($parentNode->getType()) {
				case IdentifierType::EntityRoot:
				case IdentifierType::EntityProperty:
					$node->setType(IdentifierType::EntityProperty);
					break;
				
				case IdentifierType::SubqueryRoot:
				case IdentifierType::SubqueryProperty:
					$node->setType(IdentifierType::SubqueryProperty);
					break;
				
				case IdentifierType::JsonRoot:
				case IdentifierType::JsonProperty:
					$node->setType(IdentifierType::JsonProperty);
					break;
			}
		}
	}