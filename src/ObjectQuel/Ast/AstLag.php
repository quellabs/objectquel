<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * LAG(expr) — value of expr from the previous row in the partition, ordered
	 * by the inline `sort by`. v1 scope cut: offset is fixed at 1 and there is
	 * no explicit default value (both NULL when there is no previous row) —
	 * matching the standard LAG(expr, offset, default) signature is deferred
	 * until a real need for it shows up, same as the frame-clause cut.
	 */
	class AstLag extends AstAggregate {

		/**
		 * @param AstInterface $expression The value expression to read from the previous row
		 * @param array<int, array{ast: AstInterface, order: string}>|null $order
		 * @param array<int, AstInterface>|null $partitionBy
		 */
		public function __construct(AstInterface $expression, ?array $order = null, ?array $partitionBy = null) {
			parent::__construct($expression, null, $order, $partitionBy);
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "LAG";
		}

		/**
		 * Narrows the return type from ?AstInterface to AstInterface — the value
		 * expression is a required constructor argument, never null.
		 * @return AstInterface
		 */
		public function getIdentifier(): AstInterface {
			$identifier = parent::getIdentifier();

			if ($identifier === null) {
				throw new \LogicException('AstLag has no value expression — the AST is in an invalid state.');
			}

			return $identifier;
		}

		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->getIdentifier()->deepClone(), $this->cloneOrder(), $this->clonePartitionBy());
		}
	}
