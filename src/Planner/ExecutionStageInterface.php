<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Interface for execution stages in a query execution plan.
	 *
	 * An execution stage represents a discrete unit of query execution that can be
	 * independently executed and potentially cached. Stages are executed in sequence,
	 * with each stage's results potentially feeding into subsequent stages.
	 *
	 * Common stage types include:
	 * - Database queries (ExecutionStage)
	 * - In-memory processing of non-database ranges
	 */
	interface ExecutionStageInterface {
		
		/**
		 * Get the unique identifier for this execution stage.
		 * Used for logging, debugging, and tracking stage execution order.
		 * @return string Unique stage identifier
		 */
		public function getName(): string;

		/**
		 * Get static parameters to be bound to the query.
		 * Static parameters are values that don't change between executions and can be
		 * safely included in cache keys (e.g., user-provided filter values).
		 * @return array<string, mixed> Associative array of parameter names to values
		 */
		public function getStaticParams(): array;
		
		/**
		 * Returns the query AST to execute for this stage
		 * @return AstRetrieve The ObjectQuel query AST associated with this stage
		 */
		public function getQuery(): AstRetrieve;
		
		/**
		 * Returns the range associated with this execution stage.
		 * For database stages this is the primary range used to detect JSON vs database execution.
		 * @return AstRange|null
		 */
		public function getRange(): ?AstRange;
	}