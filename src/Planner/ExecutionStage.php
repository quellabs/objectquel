<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Planner\Visitors\DetectNotNullCheckOnRange;
	
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
		 * @var array<string, mixed>
		 */
		private array $staticParams;
		
		/**
		 * The conditions for a join operation with other stages
		 * @var AstInterface|null
		 */
		private ?AstInterface $joinConditions;
		
		/**
		 * Defines where this stage should execute its query:
		 * - AstRangeDatabase: Query executes against a database table/view
		 * - AstRangeJsonSource: Query executes against JSON data source
		 * - null: Range will be determined dynamically or inherited
		 */
		private ?AstRange $range;
		
		/**
		 * Create a new execution stage
		 * @param string $name Unique identifier for this stage within the execution plan
		 * @param AstRetrieve $query The ObjectQuel query AST for this stage
		 * @param ?AstRange $range The data source range, or null if to be determined later
		 * @param array<string, mixed> $staticParams Fixed parameters that don't depend on other stages' results
		 * @param AstInterface|null $joinConditions The conditions for joining this stage with other stages
		 */
		public function __construct(
			string        $name,
			AstRetrieve   $query,
			?AstRange     $range,
			array         $staticParams = [],
			?AstInterface $joinConditions = null
		) {
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
		 * Get the unique name/identifier of this execution stage
		 * @return string The unique stage name
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the static parameters configured for this stage
		 * @return array<string, mixed> Associative array of parameter names to values
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
		 * @return AstRange|null The configured range
		 */
		public function getRange(): ?AstRange {
			return $this->range;
		}
		
		/**
		 * Sets the data source range for this stage
		 * @param AstRange|null $range New range or null
		 * @return void
		 */
		public function setRange(?AstRange $range): void {
			$this->range = $range;
		}
		
		/**
		 * Determines the type of join this stage should perform when combining
		 * its results with the accumulated result set.
		 *
		 * For database stages the join type is determined by QuelToSQL (INNER vs LEFT
		 * based on AstRange::isRequired()). This method is only consulted for
		 * in-memory joins, which currently means JSON source stages.
		 *
		 * Rules, in order of precedence:
		 *   1. No join conditions → cross join (cartesian product).
		 *   2. JSON stage with a scalar filter condition on the JSON range → inner join.
		 *      Any filter (e.g. `j.status = "active"`) applied to a left-joined range
		 *      eliminates unmatched rows anyway, so left join would be misleading.
		 *   3. JSON stage with IS NOT NULL on the JSON range in the WHERE clause → inner join.
		 *      The user is explicitly asserting the JSON side must contribute a value.
		 *   4. Everything else → left join (enrichment: DB rows are preserved even when
		 *      the JSON source has no matching record).
		 *
		 * @return string One of 'cross', 'inner', or 'left'
		 */
		public function getJoinType(): string {
			// No join conditions → cartesian product of both result sets.
			// Checked against the property directly so PHPStan can narrow
			// ?AstInterface to AstInterface for the accept() call below.
			if ($this->joinConditions === null) {
				return 'cross';
			}
			
			// The remaining inference only applies to JSON source stages.
			// DB-to-DB join type is handled by QuelToSQL, not here.
			if (!$this->range instanceof AstRangeJsonSource) {
				return 'left';
			}

			// Signal 1: a scalar filter condition on the JSON range exists.
			// isolateFilterConditionsForRange() stored these on $this->query->getConditions()
			// in StageFactory::createRangeExecutionStage(). If any survived, the user is
			// filtering by a JSON field value, which requires a match → inner join.
			if ($this->query->getConditions() !== null) {
				return 'inner';
			}
			
			// Signal 2: IS NOT NULL on a JSON field in the WHERE clause.
			// Walk the full original condition tree (via joinConditions, which is extracted
			// from the same WHERE clause) looking for `j.field IS NOT NULL`. That assertion
			// means the user requires the JSON side to contribute a value → inner join.
			$visitor = new DetectNotNullCheckOnRange($this->range->getName());
			$this->joinConditions->accept($visitor);
			
			if ($visitor->isFound()) {
				return 'inner';
			}
			
			// No evidence of a required match: default to left join (enrichment).
			return 'left';
		}
	}