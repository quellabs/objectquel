<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Validates that all identifier references point to an existing range variable.
	 *
	 * Example:
	 *
	 *   range of c is Customer
	 *   retrieve (a.street)
	 *
	 * In this case, "a" is referenced but was never declared as a range,
	 * so a SemanticException is thrown.
	 */
	class ValidateRangeReferencesExist implements AstVisitorInterface {
		
		/**
		 * Visits an AST node and validates that entity-style identifiers
		 * reference a known range variable.
		 *
		 * @param AstInterface $node The AST node being visited.
		 *
		 * @throws SemanticException When an identifier references
		 *                           an undefined range variable.
		 */
		public function visitNode(AstInterface $node): void {
			// Only identifiers can reference ranges.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only check root nodes
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// If ResolveRootIdentifierType set a type, we know the range exists
			if ($node->getType() !== IdentifierType::Unresolved) {
				return;
			}
			
			// The identifier references a range variable that does not exist.
			throw new SemanticException(sprintf(
				"Undefined range reference '%s'. Make sure the range is declared before it is used.",
				$node->getName()
			));
		}
	}