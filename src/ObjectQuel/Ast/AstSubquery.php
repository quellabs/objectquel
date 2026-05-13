<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeWithAggregation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeWithConditions;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSubquery
	 */
	class AstSubquery extends Ast implements NodeWithConditions, NodeWithAggregation {
		
		public const string TYPE_SCALAR = 'scalar';        // (SELECT SUM(...))
		public const string TYPE_EXISTS = 'exists';        // EXISTS(SELECT 1 ...)
		public const string TYPE_CASE_WHEN = 'case_when';  // CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
		public const string TYPE_WINDOW = 'window';
		
		private string $type;
		private ?string $origin;
		private ?AstInterface $conditions;
		protected ?AstInterface $aggregation;
		
		/** @var AstRange[] */
		private array $correlatedRanges;
		
		/**
		 * AstSubquery constructor
		 * @param AstInterface|null $aggregation
		 * @param string $type
		 * @param AstRange[] $correlatedRanges
		 * @param AstInterface|null $conditions
		 * @param string|null $origin
		 */
		public function __construct(
			string        $type = self::TYPE_SCALAR,
			?AstInterface $aggregation = null,
			array         $correlatedRanges = [],
			?AstInterface $conditions = null,
			?string       $origin = null
		) {
			$this->type = $type;
			$this->aggregation = $aggregation;
			$this->conditions = $conditions;
			$this->correlatedRanges = $correlatedRanges;
			$this->origin = $origin;
			
			$this->aggregation?->setParent($this);
		}
		
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->aggregation?->accept($visitor);
		}
		
		/**
		 * Returns the AST type
		 * @return string
		 */
		public function getType(): string {
			return $this->type;
		}
		
		/**
		 * If the subquery case in place of an aggregation, this returns the original aggregation type
		 * @return string|null The left operand.
		 */
		public function getOrigin(): ?string {
			return $this->origin;
		}
		
		/**
		 * If the subquery case in place of an aggregation, this returns the original aggregation type
		 * @param string $origin
		 * @return void The left operand.
		 */
		public function SetOrigin(string $origin): void {
			$this->origin = $origin;
		}
		
		/**
		 * Get the subquery expression
		 * @return AstInterface|null The left operand.
		 */
		public function getAggregation(): ?AstInterface {
			return $this->aggregation;
		}
		
		/**
		 * Get the subquery expression
		 * @param AstInterface $aggregation
		 * @return void The left operand.
		 */
		public function setAggregation(AstInterface $aggregation): void {
			$this->aggregation = $aggregation;
		}
		
		/**
		 * Get all ranges referenced in this subquery
		 * @return AstRange[]
		 */
		public function getCorrelatedRanges(): array {
			return $this->correlatedRanges;
		}
		
		/**
		 * Returns contents of WHERE
		 * @return AstInterface|null
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Replaces the WHERE conditions.
		 * @param AstInterface|null $conditions
		 * @return void
		 */
		public function setConditions(?AstInterface $conditions): void {
			$this->conditions = $conditions;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedAggregation = $this->aggregation?->deepClone();
			
			// Clone the conditions
			$clonedConditions = $this->conditions?->deepClone();
			
			// Clone the ranges
			$clonedCorrelatedRanges = [];
			foreach ($this->correlatedRanges as $range) {
				$clonedCorrelatedRanges[] = $range->deepClone();
			}
			
			// Create new instance with cloned identifier
			// Return cloned node
			// @phpstan-ignore-next-line new.static
			return new static($this->type, $clonedAggregation, $clonedCorrelatedRanges, $clonedConditions);
		}
	}