<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	/**
	 * Interface for execution stages in a query execution plan.
	 *
	 * An execution stage represents a discrete unit of query execution that can be
	 * independently executed and potentially cached. Stages are executed in sequence,
	 * with each stage's results potentially feeding into subsequent stages.
	 *
	 * Common stage types include:
	 * - Database queries (ExecutionStage)
	 * - Temporary table creation (ExecutionStageTempTable)
	 * - In-memory processing of non-database ranges
	 */
	interface ExecutionStageInterface {
		
		/**
		 * Get the unique identifier for this execution stage.
		 * Used for logging, debugging, and tracking stage execution order.
		 *
		 * @return string Unique stage identifier
		 */
		public function getName(): string;
		
		/**
		 * Check if this stage has a custom result processor.
		 * Result processors transform raw query results into the desired output format,
		 * such as joining with other stage results or applying post-query filtering.
		 *
		 * @return bool True if stage has a result processor, false otherwise
		 */
		public function hasResultProcessor(): bool;
		
		/**
		 * Get the result processor callable for this stage.
		 * The processor receives raw results and transforms them according to stage requirements.
		 * Returns null if no custom processing is needed.
		 *
		 * @return callable|null Result processing function or null
		 */
		public function getResultProcessor(): ?callable;
		
		/**
		 * Get static parameters to be bound to the query.
		 * Static parameters are values that don't change between executions and can be
		 * safely included in cache keys (e.g., user-provided filter values).
		 *
		 * @return array Associative array of parameter names to values
		 */
		public function getStaticParams(): array;
	}