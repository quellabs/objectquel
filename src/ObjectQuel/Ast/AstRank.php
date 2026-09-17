<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * RANK() — sequential rank within the partition, with gaps after ties.
	 * No value argument; only meaningful with an inline `sort by`.
	 */
	class AstRank extends AstAggregate {

		/**
		 * @param array<int, array{ast: \Quellabs\ObjectQuel\ObjectQuel\AstInterface, order: string}>|null $order
		 */
		public function __construct(?array $order = null) {
			parent::__construct(null, null, $order);
		}

		public function getType(): string {
			return "RANK";
		}

		public function getReturnType(): ?string {
			return "integer";
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneOrder());
		}
	}
