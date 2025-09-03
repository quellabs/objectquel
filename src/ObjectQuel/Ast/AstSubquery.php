<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSubquery
	 */
	class AstSubquery extends Ast {
		
		public const string TYPE_SCALAR = 'scalar';        // (SELECT SUM(...))
		public const string TYPE_EXISTS = 'exists';        // EXISTS(SELECT 1 ...)
		public const string TYPE_CASE_WHEN = 'case_when';  // CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
		public const string TYPE_WINDOW = 'window';
		
		/**
		 * @var AstInterface
		 */
		protected ?AstInterface $aggregation;
		private string $type;
		private array $correlatedRanges;
		private ?AstInterface $conditions;
		
		/**
		 * AstSubquery constructor
		 * @param AstInterface|null $aggregation
		 * @param string $type
		 * @param array $correlatedRanges
		 * @param AstInterface|null $conditions
		 */
		public function __construct(
			string       $type = self::TYPE_SCALAR,
			?AstInterface $aggregation = null,
			array        $correlatedRanges = [],
			?AstInterface $conditions = null
		) {
			$this->type = $type;
			$this->aggregation = $aggregation;
			$this->conditions = $conditions;
			$this->correlatedRanges = $correlatedRanges;
			
			if ($aggregation !== null) {
				$this->aggregation->setParent($this);
			}
		}
		
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			if ($this->aggregation !== null) {
				$this->aggregation->accept($visitor);
			}
		}
		
		public function getType(): string {
			return $this->type;
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
		 */
		public function getCorrelatedRanges(): array {
			return $this->correlatedRanges;
		}
		
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedAggregation = $this->aggregation->deepClone();
			$clonedConditions = $this->conditions->deepClone();

			$clonedCorrelatedRanges = [];
			foreach($this->correlatedRanges as $range) {
				$clonedCorrelatedRanges[] = $range->deepClone();
			}
			
			// Create new instance with cloned identifier
			// @phpstan-ignore-next-line new.static
			// Return cloned node
			return new static($this->type, $clonedAggregation, $clonedCorrelatedRanges, $clonedConditions);
		}
	}