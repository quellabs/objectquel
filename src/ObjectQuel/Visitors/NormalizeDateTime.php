<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * Expresses the datetime operands of binary expressions as Unix timestamps, which date arithmetic works in.
	 * A known native datetime operand (column, typed routine call, routine variable or cursor field)
	 * is wrapped in AstDate; a date string compared with one becomes an integer.
	 * Bound parameters are converted at execution by CoerceDateTimeParameters.
	 */
	class NormalizeDateTime implements AstVisitorInterface {

		/** Comparison operators whose string operand is read as a date */
		private const array COMPARISON_OPERATORS = ['=', '<>', '>', '>=', '<', '<='];

		private ResolveType $resolveType;

		/**
		 * @param EntityStore $entityStore Used to look up column types
		 * @param ResolveType|null $resolveType Type resolver; defaults to one that knows entity columns only
		 */
		public function __construct(EntityStore $entityStore, ?ResolveType $resolveType = null) {
			$this->resolveType = $resolveType ?? new ResolveType($entityStore);
		}

		/**
		 * Wraps known datetime operands, then converts a date string compared with a timestamp.
		 * @param AstInterface $node
		 * @return void
		 * @throws EntityResolutionException|QuelException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof NodeBinary) {
				return;
			}

			if ($this->isDateTimeValue($node->getLeft())) {
				$node->setLeft($this->attach(new AstDate($node->getLeft(), null), $node));
			}

			if ($this->isDateTimeValue($node->getRight())) {
				$node->setRight($this->attach(new AstDate($node->getRight(), null), $node));
			}

			if (!in_array($node->getOperator(), self::COMPARISON_OPERATORS, true)) {
				return;
			}

			if ($this->isTimestamp($node->getLeft()) && $node->getRight() instanceof AstString) {
				$node->setRight($this->attach($this->timestampLiteral($node->getRight()), $node));
			}

			if ($this->isTimestamp($node->getRight()) && $node->getLeft() instanceof AstString) {
				$node->setLeft($this->attach($this->timestampLiteral($node->getLeft()), $node));
			}
		}

		/**
		 * @param AstInterface $operand Operand of a binary expression
		 * @return bool True when the expression returns a native datetime value
		 * @throws EntityResolutionException
		 */
		private function isDateTimeValue(AstInterface $operand): bool {
			if (!$operand instanceof AstIdentifier) {
				return $this->resolveType->inferReturnType($operand) === '\\DateTime';
			}

			// The chain's last node carries the value's type: "createdAt" in "p.createdAt"
			$terminal = $operand;

			while ($terminal->getNext() !== null) {
				$terminal = $terminal->getNext();
			}

			return $this->resolveType->inferReturnTypeOfIdentifier($terminal) === '\DateTime';
		}

		/**
		 * @param AstInterface $operand Operand of a comparison
		 * @return bool True when it's a point in time as a Unix timestamp, not an interval
		 */
		private function isTimestamp(AstInterface $operand): bool {
			return $operand instanceof AstDate && !$operand->isInterval();
		}

		/**
		 * @param AstString $literal Date string, e.g. "2024-01-01" or "2024-01-01 12:00:00"
		 * @return AstNumber The same point in time as a Unix timestamp
		 * @throws QuelException When the string isn't a date
		 */
		private function timestampLiteral(AstString $literal): AstNumber {
			$timestamp = CoerceDateTimeParameters::toTimestamp($literal->getValue());

			if ($timestamp === null) {
				throw new QuelException("'{$literal->getValue()}' is compared with a datetime and must be a 'Y-m-d H:i:s' or 'Y-m-d' string, or a Unix timestamp.", 'type_error');
			}

			return new AstNumber((string)$timestamp);
		}

		/**
		 * @param AstInterface $replacement Node taking an operand's place
		 * @param NodeBinary $parent The binary expression it goes into
		 * @return AstInterface The replacement, with its parent set
		 */
		private function attach(AstInterface $replacement, NodeBinary $parent): AstInterface {
			$replacement->setParent($parent);
			return $replacement;
		}
	}
