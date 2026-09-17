<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * ROW_NUMBER() — unique sequential position within the partition; no ties.
	 * No value argument; only meaningful with an inline `sort by`.
	 */
	class AstRowNumber extends AstAggregate {

		/**
		 * @param array<int, array{ast: \Quellabs\ObjectQuel\ObjectQuel\AstInterface, order: string}>|null $order
		 */
		public function __construct(?array $order = null) {
			parent::__construct(null, null, $order);
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "ROW_NUMBER";
		}

		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "integer";
		}

		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneOrder());
		}
	}
