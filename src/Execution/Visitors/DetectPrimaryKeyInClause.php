<?php
	
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A visitor implementation that traverses the AST to identify the first IN() clause
	 * used on the primary key. This class follows the Visitor design pattern to separate
	 * the traversal logic from the AST structure. When the target node is found, it
	 * throws a GetMainEntityInAstException containing the found node, effectively terminating
	 * the traversal.
	 */
	class DetectPrimaryKeyInClause implements AstVisitorInterface {

		/** @var AstRange The resolved range the primary key must belong to */
		private AstRange $range;

		/** @var string The primary key's property name on that range */
		private string $propertyName;

		/**
		 * ContainsRange constructor.
		 * @param AstRange $range The resolved range the primary key belongs to
		 * @param string $propertyName The primary key's property name on that range
		 */
		public function __construct(AstRange $range, string $propertyName) {
			$this->range = $range;
			$this->propertyName = $propertyName;
		}

		/**
		 * Traverse through the AST and throw an exception as soon as the AstIn node for the primary key is found.
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 * @throws DetectPrimaryKeyInClauseException When the target AstIn node is found
		 */
		public function visitNode(AstInterface $node): void {
			// If the node is not an AstIn instance, we're not interested in it
			if (!$node instanceof AstIn) {
				return;
			}

			// Verify that the identifier is actually an AstIdentifier instance
			$identifier = $node->getIdentifier();

			if (!$identifier instanceof AstIdentifier) {
				return;
			}

			// A parsed "range.property" identifier carries the range on its base
			// node and the property as a single next-segment — matching it against
			// a manually-built identifier (which names its base after the entity,
			// not the range alias) would never succeed, so compare range + property
			// name directly instead.
			if ($identifier->getRange()?->getName() !== $this->range->getName()) {
				return;
			}

			if (
				$identifier->getNext() === null ||
				$identifier->getNext()->getName() !== $this->propertyName ||
				$identifier->getNext()->getNext() !== null
			) {
				return;
			}

			// If we've reached this point, we've found the AstIn node for our primary key
			// Throw an exception with the found node to interrupt the traversal
			throw new DetectPrimaryKeyInClauseException($node);
		}
	}