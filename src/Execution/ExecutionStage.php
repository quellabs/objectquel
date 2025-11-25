<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Represents a single execution stage within a decomposed query execution plan.
	 * Each stage represents a discrete query that must be executed to contribute to
	 * the final result set. Stages can depend on results from other stages, allowing
	 * for complex query composition where the output of one query becomes the input
	 * for another.
	 *
	 * Example usage:
	 * - Stage 1: Retrieve base data from database
	 * - Stage 2: Use Stage 1 results to query additional related data
	 * - Stage 3: Join results from stages 1 and 2 based on join conditions
	 */
	class ExecutionStage implements ExecutionStageInterface {

		/**
		 * Unique name/identifier for this stage
		 * @var string
		 */
		private string $name;
		
		/**
		 * The ObjectQuel query to execute for this stage
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
		 * The conditions for a join operation with other stages
		 * @var AstInterface|null
		 */
		private ?AstInterface $joinConditions = null;
		
		/**
		 * Defines where this stage should execute its query:
		 * - AstRangeDatabase: Query executes against a database table/view
		 * - AstRangeJsonSource: Query executes against JSON data source
		 * - null: Range will be determined dynamically or inherited
		 * @var AstRangeDatabase|AstRangeJsonSource|null
		 */
		private AstRangeDatabase|AstRangeJsonSource|null $range;
		
		/**
		 * Create a new execution stage
		 * @param string $name Unique identifier for this stage within the execution plan
		 * @param AstRetrieve $query The ObjectQuel query AST for this stage
		 * @param AstRangeDatabase|AstRangeJsonSource|null $range The data source range, or null if to be determined later
		 * @param array $staticParams Fixed parameters that don't depend on other stages' results
		 * @param AstInterface|null $joinConditions The conditions for joining this stage with other stages
		 */
		public function __construct(string $name, AstRetrieve $query, AstRangeDatabase|AstRangeJsonSource|null $range, array $staticParams = [], ?AstInterface $joinConditions = null) {
			$this->name = $name;
			$this->query = $query;
			$this->range = $range;
			$this->staticParams = $staticParams;
			$this->joinConditions = $joinConditions;
		}
		
		/**
		 * Returns the query AST to execute for this stage
		 * @return AstRetrieve The ObjectQuel query AST associated with this stage
		 */
		public function getQuery(): AstRetrieve {
			return $this->query;
		}
		
		/**
		 * Checks if this stage has a result processor configured
		 * @return bool True if a result processor has been configured, false otherwise
		 */
		public function hasResultProcessor(): bool {
			return $this->resultProcessor !== null;
		}
		
		/**
		 * Returns the result processor function if one is configured
		 * @return callable|null The processor function or null if none is set
		 */
		public function getResultProcessor(): ?callable {
			return $this->resultProcessor;
		}
		
		/**
		 * Set a processor function to transform results before passing to dependent stages
		 * @param callable|null $processor Function that takes results array and returns processed array
		 * @return ExecutionStage This stage instance for method chaining
		 */
		public function setResultProcessor(?callable $processor): self {
			$this->resultProcessor = $processor;
			return $this;
		}
		
		/**
		 * Get the unique name/identifier of this execution stage
		 * @return string The unique stage name
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the static parameters configured for this stage
		 * @return array Associative array of parameter names to values
		 */
		public function getStaticParams(): array {
			return $this->staticParams;
		}
		
		/**
		 * Returns the join conditions for this stage
		 * @return AstInterface|null The join condition AST or null if no conditions
		 */
		public function getJoinConditions(): ?AstInterface {
			return $this->joinConditions;
		}
		
		/**
		 * Updates the join conditions for this stage
		 * @param AstInterface|null $joinConditions New join conditions or null to remove
		 * @return void
		 */
		public function setJoinConditions(?AstInterface $joinConditions): void {
			$this->joinConditions = $joinConditions;
		}
		
		/**
		 * Gets the data source range/target for this stage's query
		 * @return AstRangeDatabase|AstRangeJsonSource|null The configured range
		 */
		public function getRange(): AstRangeDatabase|AstRangeJsonSource|null {
			return $this->range;
		}
		
		/**
		 * Sets the data source range for this stage
		 * @param AstRangeDatabase|AstRangeJsonSource|null $range New range or null
		 * @return void
		 */
		public function setRange(AstRangeDatabase|AstRangeJsonSource|null $range): void {
			$this->range = $range;
		}
		
		/**
		 * Determines the type of join this stage should perform
		 *
		 * The join type affects how this stage's results are combined with
		 * results from other stages:
		 * - 'cross': No join conditions, creates cartesian product
		 * - 'left': Left join based on join conditions
		 *
		 * @todo Implement more sophisticated logic to determine optimal join type
		 * @todo Add support for 'inner', 'right', 'full outer' join types
		 * @todo Consider query optimization hints for join type selection
		 *
		 * @return string The join type ('cross' or 'left' currently)
		 */
		public function getJoinType(): string {
			// If no join conditions are specified, default to cross join
			// which creates a cartesian product of all result sets
			if ($this->getJoinConditions() === null) {
				return 'cross';
			}
			
			// When join conditions exist, default to left join
			return 'left';
		}
	}