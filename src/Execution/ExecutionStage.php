<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Represents a single execution stage within a decomposed query execution plan.
	 * Each stage represents a discrete query that must be executed to contribute to
	 * the final result set.
	 *
	 * Stages can depend on results from other stages, allowing for complex query
	 * composition where the output of one query becomes the input for another.
	 */
	class ExecutionStage {
		/**
		 * Unique name/identifier for this stage
		 * Used to reference this stage from other stages and from the execution plan
		 * @var string
		 */
		private string $name;
		
		/**
		 * The ObjectQuel query to execute for this stage
		 * This contains the parsed query string that will be parsed and executed
		 * @var AstRetrieve
		 */
		private AstRetrieve $query;
		
		/**
		 * Static parameters that are provided at the plan creation time
		 * These are fixed values that don't depend on the execution of other stages
		 * @var array
		 */
		private array $staticParams = [];
		
		/**
		 * Post-processing function to apply to results before passing to next stages
		 * Allows for transformation or filtering of results before they're used by dependent stages
		 * @var callable|null
		 */
		private $resultProcessor = null;
		
		/**
		 * The conditions for a join
		 * @var null
		 */
		private ?AstInterface $joinConditions = null;
		
		/**
		 * The attached range
		 * @var AstRangeDatabase|AstRangeJsonSource|null
		 */
		private AstRangeDatabase|AstRangeJsonSource|null $range;
		
		/**
		 * Create a new execution stage
		 * @param string $name Unique identifier for this stage
		 * @param AstRetrieve $query The ObjectQuel query for this stage
		 * @param AstRange|null $range The attached range, or null if none attached
		 * @param array $staticParams Fixed parameters that don't depend on other stages
		 * @param AstInterface|null $joinConditions The conditions for joining this stage with the final result
		 */
		public function __construct(string $name, AstRetrieve $query, ?AstRange $range, array $staticParams = [], ?AstInterface $joinConditions=null) {
			$this->name = $name;
			$this->query = $query;
			$this->range = $range;
			$this->staticParams = $staticParams;
			$this->joinConditions = $joinConditions;
		}
		
		/**
		 * Returns the query to execute
		 * @return AstRetrieve The ObjectQuel query associated with this stage
		 */
		public function getQuery(): AstRetrieve {
			return $this->query;
		}
		
		/**
		 * Returns true if the stage has a result processor
		 * @return bool Whether a result processor has been configured
		 */
		public function hasResultProcessor(): bool {
			return $this->resultProcessor !== null;
		}
		
		/**
		 * Returns the result processor
		 * @return callable|null The processor function or null if none is set
		 */
		public function getResultProcessor(): ?callable {
			return $this->resultProcessor;
		}
		
		/**
		 * Set a processor function to transform results before passing to next stages
		 * @param callable|null $processor Function that takes results and returns processed results
		 * @return ExecutionStage This stage instance for method chaining
		 */
		public function setResultProcessor(?callable $processor): self {
			$this->resultProcessor = $processor;
			return $this;
		}
		
		/**
		 * Get the name of this stage
		 * @return string Stage name
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the static parameters configured for this stage
		 * Static parameters are fixed values provided at stage creation time
		 * that don't depend on other stages' execution.
		 * @return array The static parameters for this stage
		 */
		public function getStaticParams(): array {
			return $this->staticParams;
		}
		
		/**
		 * Returns the join conditions
		 * @return AstInterface|null
		 */
		public function getJoinConditions(): ?AstInterface {
			return $this->joinConditions;
		}
		
		/**
		 * Updates the join conditions
		 * @param AstInterface|null $joinConditions
		 * @return void
		 */
		public function setJoinConditions(?AstInterface $joinConditions): void {
			$this->joinConditions = $joinConditions;
		}
		
		/**
		 * Gets the query type
		 * @return AstRangeDatabase|AstRangeJsonSource|null
		 */
		public function getRange(): AstRangeDatabase|AstRangeJsonSource|null {
			return $this->range;
		}
		
		/**
		 * Sets the range
		 * @return void
		 */
		public function setRange(?AstRange $range): void {
			$this->range = $range;
		}
		
		/**
		 * Returns the join type (always 'left' for now)
		 * @todo Implement code to determine the join type
		 * @return string
		 */
		public function getJoinType(): string {
			if ($this->getJoinConditions() === null) {
				return 'cross';
			} else {
				return 'left';
			}
		}
		
	}