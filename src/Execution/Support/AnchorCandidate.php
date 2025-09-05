<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	
	/**
	 * Represents a table that could potentially serve as the query anchor.
	 *
	 * In ObjectQuel query execution, an anchor candidate represents a potential
	 * starting point for query execution. The query planner evaluates multiple
	 * anchor candidates to determine the most efficient execution path based on
	 * factors like available indexes, selectivity, and join strategies.
	 */
	class AnchorCandidate {

		/** @var int Position/identifier of this candidate in the query plan */
		private int $index;
		
		/** @var AstRange The AST range this candidate covers in the query */
		private AstRange $range;
		
		/** @var int Higher scores indicate better anchor candidates for optimization */
		private int $priorityScore;
		
		/** @var string The execution strategy to use if this candidate is selected (e.g., 'index_scan', 'table_scan', 'join') */
		private string $strategy;
		
		/** @var bool Whether this candidate can actually be used as an anchor given current constraints */
		private bool $viable;
		
		/**
		 * Creates a new anchor candidate for query planning.
		 * @param int $index Position identifier for this candidate
		 * @param AstRange $range AST range this candidate represents
		 * @param int $priorityScore Optimization score (higher = better candidate)
		 * @param string $strategy Execution strategy name
		 * @param bool $viable Whether this candidate is usable given current constraints
		 */
		public function __construct(
			int      $index,
			AstRange $range,
			int      $priorityScore,
			string   $strategy,
			bool     $viable
		) {
			$this->viable = $viable;
			$this->strategy = $strategy;
			$this->priorityScore = $priorityScore;
			$this->range = $range;
			$this->index = $index;
		}
		
		/**
		 * Gets the position identifier for this anchor candidate.
		 * @return int The candidate's index position
		 */
		public function getIndex(): int {
			return $this->index;
		}
		
		/**
		 * Gets the AST range this candidate covers.
		 * @return AstRange The range of the abstract syntax tree this candidate represents
		 */
		public function getRange(): AstRange {
			return $this->range;
		}
		
		/**
		 * Gets the optimization priority score for this candidate.
		 * @return int Priority score (higher values indicate better candidates)
		 */
		public function getPriorityScore(): int {
			return $this->priorityScore;
		}
		
		/**
		 * Gets the execution strategy name for this candidate.
		 * @return string Strategy identifier (e.g., 'index_scan', 'hash_join')
		 */
		public function getStrategy(): string {
			return $this->strategy;
		}
		
		/**
		 * Checks if this candidate is viable for use as a query anchor.
		 * @return bool True if this candidate can be used given current constraints
		 */
		public function isViable(): bool {
			return $this->viable;
		}
	}