<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * NTILE(n) — distributes partition rows into n roughly-equal buckets.
	 * The value argument is the bucket count, not a column reference; only
	 * meaningful with an inline `sort by`.
	 */
	class AstNtile extends AstAggregate {

		/**
		 * @param AstInterface $bucketCount The number of buckets (e.g. AstNumber(4))
		 * @param array<int, array{ast: AstInterface, order: string}>|null $order
		 * @param array<int, AstInterface>|null $partitionBy
		 */
		public function __construct(AstInterface $bucketCount, ?array $order = null, ?array $partitionBy = null) {
			parent::__construct($bucketCount, null, $order, $partitionBy);
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "NTILE";
		}

		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "integer";
		}

		/**
		 * Narrows the return type from ?AstInterface to AstInterface — the bucket
		 * count is a required constructor argument, never null.
		 * @return AstInterface
		 */
		public function getIdentifier(): AstInterface {
			$identifier = parent::getIdentifier();

			if ($identifier === null) {
				throw new \LogicException('AstNtile has no bucket count — the AST is in an invalid state.');
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
