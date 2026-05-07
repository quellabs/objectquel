<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class RangeOnlyReferencesOtherRanges
	 */
	class RangeOnlyReferencesOtherRanges implements AstVisitorInterface {
		
		/** @var list<string> */
		private array $rangeNames;
		
		/**
		 * Constructor
		 * @param AstRetrieve $ast
		 */
		public function __construct(AstRetrieve $ast) {
			foreach ($ast->getRanges() as $range) {
				$this->rangeNames[] = $range->getName();
			}
		}
		
		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @throws SemanticException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only check base identifiers
			if ($node->hasParentIdentifier()) {
				return;
			}
			
			// Fetch the range name
			$range = $node->getRange();
			
			// Check if the range name is in the known ranges list
			// If not, it references something in the retrieve() list
			if ($range === null || !in_array($range->getName(), $this->rangeNames)) {
				throw new SemanticException(
					sprintf(
						"'%s' in the 'via' clause of range '%%s' does not reference a declared range. Valid ranges are: %s.",
						$node->getCompleteName(),
						implode(', ', $this->rangeNames)
					)
				);
			}
		}
	}