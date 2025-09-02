<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	
	/**
	 * Class AstSubquery
	 */
	class AstSubquery extends Ast {
		
		public const string TYPE_SCALAR = 'scalar';        // (SELECT SUM(...))
		public const string TYPE_EXISTS = 'exists';        // EXISTS(SELECT 1 ...)
		public const string TYPE_CASE_WHEN = 'case_when';  // CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
		
		/**
		 * @var AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU The right-hand operand of the AND expression.
		 */
		protected AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregation;
		private string $type;
		
		/**
		 * AstSubquery constructor
		 * @param AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregation
		 * @param string $type
		 */
		public function __construct(
			AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregation,
			string                                                                $type = self::TYPE_SCALAR
		) {
			$this->aggregation = $aggregation;
			$this->type = $type;
			$this->aggregation->setParent($this);
		}
		
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->aggregation->accept($visitor);
		}
		
		public function getExpression(): ?AstInterface {
			return $this->aggregation->getIdentifier();
		}
		
		/**
		 * Get the subquery expression
		 * @return AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU The left operand.
		 */
		public function getAggregation(): AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU {
			return $this->aggregation;
		}
		
		/**
		 * Get the subquery expression
		 * @param AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregation
		 * @return void The left operand.
		 */
		public function setAggregation(AstSubquery|AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregation): void {
			$this->aggregation = $aggregation;
		}
		
		public function getType(): string {
			return $this->type;
		}
		
		/**
		 * Get all ranges referenced in this subquery
		 */
		public function getAllRanges(): array {
			$visitor = new CollectRanges();
			$this->aggregation->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		public function getSubqueryRanges(): array {
			// Only ranges actually used in the aggregation expression
			$visitor = new CollectRanges();
			$this->aggregation->getIdentifier()->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		public function getConditions(): ?AstInterface {
			if ($this->aggregation->getConditions() !== null) {
				return $this->aggregation->getConditions();
			}
			
			$range = $this->getMainRange();
			return $range?->getJoinProperty();
		}
		
		/**
		 * Get the main range for subquery FROM clause
		 * Priority: outer query main range > required ranges > first available
		 */
		public function getMainRange(): ?AstRange {
			// Fetch all ranges used in the aggregate
			$subqueryRanges = $this->getSubqueryRanges();
			
			// No ranges found
			if (empty($subqueryRanges)) {
				return null;
			}
			
			// First priority: outer query's main range if it's used in the aggregation
			foreach ($subqueryRanges as $range) {
				if ($range->getJoinProperty() === null) { // No join property = likely main range
					return $range;
				}
			}
			
			// Second priority: first required (INNER JOIN) range
			foreach ($subqueryRanges as $range) {
				if ($range->isRequired()) {
					return $range;
				}
			}
			
			// Fallback: first range found
			return $subqueryRanges[0];
		}
		
		/**
		 * Get ranges that should be JOINed (all subquery ranges except the main one)
		 */
		public function getJoinRanges(): array {
			// Only ranges used in aggregation
			$subqueryRanges = $this->getSubqueryRanges();
			
			// Filter out the main range
			$mainRange = $this->getMainRange();
			return array_filter($subqueryRanges, function($range) use ($mainRange) {
				return $range !== $mainRange;
			});
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedAggregation = $this->aggregation->deepClone();
			
			// Create new instance with cloned identifier
			// @phpstan-ignore-next-line new.static
			// Return cloned node
			return new static($clonedAggregation);
		}
	}