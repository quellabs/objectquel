<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Class RangeOnlyReferencesOtherRanges
	 */
	class RangeOnlyReferencesOtherRanges implements AstVisitorInterface {
		
		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @throws QuelException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			if ($node->hasParent()) {
				return;
			}
			
			if (empty($node->getRange())) {
				throw new QuelException("The 'via' clause in the range '%s' directly refers to an entity. The 'via' clause must reference another range. Please review the query and ensure that the 'via' clause correctly represents the relationship between ranges.");
			}
		}
	}