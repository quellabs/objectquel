<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	/**
	 * ExecutionPlan class manages the execution of query stages within the EntityManager system.
	 * It maintains a collection of stages and provides methods to organize and retrieve them
	 * in the proper execution order.
	 */
	class ExecutionPlan {
		
		/**
		 * Collection of execution stages that make up this plan
		 * @var ExecutionStage[]
		 */
		private array $stages;
		
		/**
		 * Returns the name of the main output stage.
		 * When there's only one stage, it returns that stage's name.
		 * Otherwise, it returns the default name "main".
		 * Will be configurable in the future.
		 * @return string The name of the main output stage
		 */
		public function getMainStageName(): string {
			foreach ($this->stages as $stage) {
				// Skip temporary table stages - they're subqueries, not main stages
				if ($stage instanceof ExecutionStageTempTable) {
					continue;
				}
				
				// Find the stage without a join property (the main FROM table)
				$range = $stage->getRange();
				
				if ($range && $range->getJoinProperty() === null) {
					return $stage->getName();
				}
			}
			
			// If no main stage found, return first stage (could be temp table or joined)
			if (!empty($this->stages)) {
				$firstKey = array_key_first($this->stages);
				return $this->stages[$firstKey]->getName();
			}
			
			throw new \RuntimeException('Execution plan has no stages');
		}
		
		/**
		 * Adds a new execution stage to the plan
		 * @param ExecutionStageInterface $stage
		 * @return void
		 */
		public function addStage(ExecutionStageInterface $stage): void {
			$this->stages[] = $stage;
		}
		
		/**
		 * Returns all stages arranged in the correct execution order that respects dependencies.
		 * The order is critical to ensure that stages are executed only after their dependencies.
		 * @return ExecutionStageInterface[] Array of stages in dependency-respecting execution order
		 * @todo Implement proper topological sorting of the stage dependency graph
		 */
		public function getStagesInOrder(): array {
			// This would implement topological sorting of the stage dependency graph
			// For now, a simple implementation assuming stages are already in order
			return $this->stages;
		}
		
		/**
		 * Returns true if the execution plan is empty, false if not
		 * @return bool
		 */
		public function isEmpty(): bool {
			return empty($this->stages);
		}
	}