<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeFunction;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Class NoExpressionsAllowedOnEntitiesValidator
	 * Validates that no operations are used on entire entities or bare range references
	 */
	class ValidateNoEntityExpressions implements AstVisitorInterface {
		
		/**
		 * Visits a node in the Ast.
		 * @param AstInterface $node
		 * @return void
		 * @throws SemanticException
		 */
		public function visitNode(AstInterface $node): void {
			// Entire entities in expressions are not allowed.
			if ($node instanceof NodeBinary) {
				if ($this->identifierIsBareRange($node->getLeft()) || $this->identifierIsBareRange($node->getRight())) {
					throw new SemanticException("Cannot use an entire entity in an expression. Please specify a field or property instead (e.g. 'order.total' instead of 'order').");
				}
			}
			
			// Entire entities in QUEL functions (is_numeric, is_float, etc) are not allowed
			if ($node instanceof NodeFunction) {
				if ($this->identifierIsBareRange($node->getValue())) {
					throw new SemanticException("Unsupported operation on entire entities. You cannot pass an entire entity as a function argument. Please specify the specific field or property you wish to use (e.g. e.id or e.name instead of e).");
				}
			}
			
			// Entire entities in aggregates (sum, avg, min, max, etc) are not allowed
			if ($node instanceof NodeAggregate) {
				if ($this->identifierIsBareRange($node->getIdentifier())) {
					throw new SemanticException("Unsupported operation on entire entities. You cannot pass an entire entity to an aggregate function. Please specify the specific field or property you wish to aggregate (e.g. e.price or e.quantity instead of e).");
				}
			}

			// Casts on bare entity references are not allowed: (int)x is meaningless.
			// Only property casts are valid: (int)x.id
			if ($node instanceof AstCast) {
				if ($this->identifierIsBareRange($node->getExpression())) {
					throw new SemanticException("Cannot cast an entire entity. You must cast a specific property instead (e.g. (int)x.id instead of (int)x).");
				}
			}
		}
		
		/**
		 * Returns true if the identifier is a bare range reference (entity or json source), false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsBareRange(AstInterface $ast): bool {
			if (!$ast instanceof AstIdentifier) {
				return false;
			}
			
			if ($ast->getType() === IdentifierType::EntityReference) {
				return true;
			}
			
			// JsonRoot is only bare when it has no chained property (y alone, not y.id)
			if ($ast->getType() === IdentifierType::JsonRoot && $ast->getNext() === null) {
				return true;
			}
			
			return false;
		}
	}