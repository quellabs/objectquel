<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Base class for aggregation
	 */
	abstract class AstAggregate extends Ast implements NodeAggregate, NodeWithConditions {

		/**
		 * @var AstInterface|null The value expression this aggregate operates over.
		 * Null for sequence functions with no value argument (rank, dense_rank, row_number).
		 */
		protected ?AstInterface $identifier;

		/**
		 * @var AstInterface|null The conditions for this aggregator
		 */
		private ?AstInterface $conditions;

		/**
		 * @var array<int, array{ast: AstInterface, order: string}>|null Inline `sort by`
		 * list. Null for a plain aggregate; non-null flips SQL generation to a window
		 * function (`OVER (PARTITION BY ... ORDER BY ...)`) instead of a plain aggregate
		 * or scalar subquery. Shape matches AstRetrieve::getSort().
		 */
		private ?array $order;

		/**
		 * @var array<int, AstInterface>|null Inline `by` list — explicit PARTITION BY
		 * columns. Null means no explicit list was written; the optimizer then falls
		 * back to inferring partition columns from the other SELECT items.
		 */
		private ?array $partitionBy;

		/**
		 * AstAggregate constructor.
		 * @param AstInterface|null $entityOrIdentifier Null for no-argument sequence functions
		 * @param AstInterface|null $conditions
		 * @param array<int, array{ast: AstInterface, order: string}>|null $order
		 * @param array<int, AstInterface>|null $partitionBy
		 */
		public function __construct(?AstInterface $entityOrIdentifier, ?AstInterface $conditions = null, ?array $order = null, ?array $partitionBy = null) {
			$this->identifier = $entityOrIdentifier;
			$this->conditions = $conditions;
			$this->order = null;
			$this->partitionBy = null;

			$this->identifier?->setParent($this);
			$conditions?->setParent($this);
			$this->setOrder($order);
			$this->setPartitionBy($partitionBy);
		}

		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->identifier?->accept($visitor);
			$this->conditions?->accept($visitor);

			foreach ($this->order ?? [] as $sortItem) {
				$sortItem['ast']->accept($visitor);
			}

			foreach ($this->partitionBy ?? [] as $expression) {
				$expression->accept($visitor);
			}
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		abstract public function getType(): string;

		/**
		 * Get the value expression this aggregate operates over.
		 * Null for no-argument sequence functions (rank, dense_rank, row_number).
		 * @return AstInterface|null
		 */
		public function getIdentifier(): ?AstInterface {
			return $this->identifier;
		}

		/**
		 * Updates the identifier with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setIdentifier(AstInterface $ast): void {
			$this->identifier = $ast;
			$this->identifier->setParent($this);
		}

		/**
		 * Returns the conditions for this aggregator
		 * @return AstInterface|null
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}

		/**
		 * Updates the conditions for this aggregator
		 * @param AstInterface|null $conditions
		 * @return void
		 */
		public function setConditions(?AstInterface $conditions): void {
			$this->conditions = $conditions;
		}

		/**
		 * Returns the inline `sort by` list, or null for a plain (non-windowed) aggregate.
		 * @return array<int, array{ast: AstInterface, order: string}>|null
		 */
		public function getOrder(): ?array {
			return $this->order;
		}

		/**
		 * Replaces the inline `sort by` list. Adopts each sort item's ast as a child.
		 * @param array<int, array{ast: AstInterface, order: string}>|null $order
		 * @return void
		 */
		public function setOrder(?array $order): void {
			$this->order = $order;

			foreach ($this->order ?? [] as $sortItem) {
				$sortItem['ast']->setParent($this);
			}
		}

		/**
		 * Clones the inline `sort by` list, preserving each item's order direction.
		 * Shared by deepClone() implementations across this class hierarchy — subclasses
		 * with a narrowed constructor (e.g. no-argument sequence functions) override
		 * deepClone() themselves but reuse this to duplicate the sort list correctly.
		 * @return array<int, array{ast: AstInterface, order: string}>|null
		 */
		protected function cloneOrder(): ?array {
			return $this->order === null ? null : array_map(
				static fn(array $sortItem): array => [
					'ast'   => $sortItem['ast']->deepClone(),
					'order' => $sortItem['order'],
				],
				$this->order
			);
		}

		/**
		 * Returns the explicit inline `by` list, or null when partitioning should be
		 * inferred from the other SELECT items instead.
		 * @return array<int, AstInterface>|null
		 */
		public function getPartitionBy(): ?array {
			return $this->partitionBy;
		}

		/**
		 * Replaces the inline `by` list. Adopts each expression as a child.
		 * @param array<int, AstInterface>|null $partitionBy
		 * @return void
		 */
		public function setPartitionBy(?array $partitionBy): void {
			$this->partitionBy = $partitionBy;

			foreach ($this->partitionBy ?? [] as $expression) {
				$expression->setParent($this);
			}
		}

		/**
		 * Clones the inline `by` list. Shared by deepClone() implementations across
		 * this class hierarchy, same as cloneOrder().
		 * @return array<int, AstInterface>|null
		 */
		protected function clonePartitionBy(): ?array {
			return $this->partitionBy === null ? null : array_map(
				static fn(AstInterface $expression): AstInterface => $expression->deepClone(),
				$this->partitionBy
			);
		}

		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedIdentifier = $this->identifier?->deepClone();
			$clonedConditions = $this->conditions?->deepClone();

			// Create new instance with cloned identifier
			// Return cloned node
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifier, $clonedConditions, $this->cloneOrder(), $this->clonePartitionBy());
		}
	}