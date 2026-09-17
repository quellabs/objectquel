<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * LEAD(expr) — value of expr from the next row in the partition, ordered
	 * by the inline `sort by`. v1 scope cut: offset is fixed at 1 and there is
	 * no explicit default value (both NULL when there is no next row) —
	 * matching the standard LEAD(expr, offset, default) signature is deferred
	 * until a real need for it shows up, same as the frame-clause cut.
	 */
	class AstLead extends AstAggregate {

		/**
		 * @param array<int, array{ast: AstInterface, order: string}>|null $order
		 */
		public function __construct(AstInterface $expression, ?array $order = null) {
			parent::__construct($expression, null, $order);
		}

		public function getType(): string {
			return "LEAD";
		}

		/**
		 * Narrows the return type from ?AstInterface to AstInterface — the value
		 * expression is a required constructor argument, never null.
		 * @return AstInterface
		 */
		public function getIdentifier(): AstInterface {
			$identifier = parent::getIdentifier();

			if ($identifier === null) {
				throw new \LogicException('AstLead has no value expression — the AST is in an invalid state.');
			}

			return $identifier;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->getIdentifier()->deepClone(), $this->cloneOrder());
		}
	}
