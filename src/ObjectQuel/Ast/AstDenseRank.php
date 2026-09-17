<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * DENSE_RANK() — sequential rank within the partition, no gaps after ties.
	 * No value argument; only meaningful with an inline `sort by`.
	 */
	class AstDenseRank extends AstAggregate {

		/**
		 * @param array<int, array{ast: \Quellabs\ObjectQuel\ObjectQuel\AstInterface, order: string}>|null $order
		 * @param array<int, \Quellabs\ObjectQuel\ObjectQuel\AstInterface>|null $partitionBy
		 */
		public function __construct(?array $order = null, ?array $partitionBy = null) {
			parent::__construct(null, null, $order, $partitionBy);
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "DENSE_RANK";
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
			return new static($this->cloneOrder(), $this->clonePartitionBy());
		}
	}
