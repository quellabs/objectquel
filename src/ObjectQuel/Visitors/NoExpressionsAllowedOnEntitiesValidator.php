<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeFunction;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class NoExpressionsAllowedOnEntitiesValidator
	 * Validates that no operations are used on entire entities
	 */
	class NoExpressionsAllowedOnEntitiesValidator implements AstVisitorInterface {
		
		/**
		 * Returns true if the identifier is an entity, false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&
				$ast->getRange() instanceof AstRangeDatabase &&
				!$ast->hasNext()
			);
		}
		
		/**
		 * Visits a node in the Ast.
		 * @param AstInterface $node
		 * @return void
		 * @throws SemanticException
		 */
		public function visitNode(AstInterface $node): void {
			// Entire entities in expressions are not allowed.
			if ($node instanceof NodeBinary) {
				if ($this->identifierIsEntity($node->getLeft()) || $this->identifierIsEntity($node->getRight())) {
					throw new SemanticException("Unsupported operation on entire entities. You cannot perform arithmetic operations directly on entities. Please specify the specific fields or properties of the entities you wish to use in the calculation.");
				}
			}
			
			// Entire entities in QUEL functions (is_numeric, is_float, etc) are not allowed
			if ($node instanceof NodeFunction) {
				if ($this->identifierIsEntity($node->getValue())) {
					throw new SemanticException("Unsupported operation on entire entities. You cannot pass an entire entity as a function argument. Please specify the specific field or property you wish to use (e.g. e.id or e.name instead of e).");
				}
			}
			
			// Entire entities in QUEL functions (is_numeric, is_float, etc) are not allowed
			if ($node instanceof NodeAggregate) {
				if ($this->identifierIsEntity($node->getIdentifier())) {
					throw new SemanticException("Unsupported operation on entire entities. You cannot pass an entire entity to an aggregate function. Please specify the specific field or property you wish to aggregate (e.g. e.price or e.quantity instead of e).");
				}
			}
		}
	}