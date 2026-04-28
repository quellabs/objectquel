<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * ExecutionPlan class manages the execution of query stages within the EntityManager system.
	 * It maintains a collection of stages and provides methods to organize and retrieve them
	 * in the proper execution order.
	 *
	 * Dependency semantics:
	 *   addDependency('B', 'A') means "stage B depends on stage A" — A must execute
	 *   before B. This is the natural reading: "B depends on A" → A comes first.
	 *
	 * Topological sort:
	 *   getStagesInOrder() implements Kahn's algorithm over the dependency graph.
	 *   Stages with no unsatisfied dependencies are placed first. A QuelException is
	 *   thrown if a cycle is detected (which indicates a programming error in plan
	 *   construction, not a user query error).
	 *
	 * Stage ordering within the same dependency level:
	 *   Kahn's algorithm processes nodes in FIFO order within each wave, which preserves
	 *   insertion order for stages at the same dependency depth. TempTableStages added
	 *   before their dependent ExecutionStages will always appear before them in the
	 *   sorted output, as long as addDependency() is called correctly by QueryDecomposer.
	 */
	class ExecutionPlan {
		
		/**
		 * Collection of execution stages that make up this plan, keyed by stage name
		 * for O(1) lookup during dependency resolution and result combination.
		 * @var array<string, ExecutionStageInterface>
		 */
		private array $stages = [];
		
		/**
		 * Dependency graph: maps stage name → list of stage names it depends on.
		 * An empty list means the stage has no dependencies and can run immediately.
		 * @var array<string, string[]>
		 */
		private array $dependencies = [];
		
		/**
		 * Returns the name of the main output stage.
		 * When there's only one stage, it returns that stage's name.
		 * Otherwise, it returns the default name "main".
		 * Will be configurable in the future.
		 * @return string The name of the main output stage
		 */
		public function getMainStageName(): string {
			foreach ($this->stages as $stage) {
				// Skip everything that's not an ExecutionStage.
				// TempTableStages are pre-execution side-effects, not result producers.
				if (!($stage instanceof ExecutionStage)) {
					continue;
				}
				
				// Find the stage without a join property (the main FROM table)
				$range = $stage->getRange();
				
				if ($range === null || $range->getJoinProperty() === null) {
					return $stage->getName();
				}
			}
			
			// If no main stage found, fall back to the first ExecutionStage
			foreach ($this->stages as $stage) {
				if ($stage instanceof ExecutionStage) {
					return $stage->getName();
				}
			}
			
			// Final fallback: first stage of any type
			if (!empty($this->stages)) {
				return array_key_first($this->stages);
			}
			
			throw new \RuntimeException('Execution plan has no stages');
		}
		
		/**
		 * Adds a new execution stage to the plan.
		 * The stage is registered with an empty dependency list by default.
		 * @param ExecutionStageInterface $stage
		 * @return void
		 */
		public function addStage(ExecutionStageInterface $stage): void {
			$this->stages[$stage->getName()] = $stage;
			
			// Initialise dependency list only if not already set (addDependency may
			// have been called before addStage in some construction orders)
			if (!isset($this->dependencies[$stage->getName()])) {
				$this->dependencies[$stage->getName()] = [];
			}
		}
		
		/**
		 * Declares that stage $dependentName must execute after stage $dependencyName.
		 *
		 * Both stages must already be added via addStage() before this is called,
		 * or a \LogicException is thrown (fail-fast on construction errors).
		 *
		 * @param string $dependentName The stage that must run AFTER $dependencyName
		 * @param string $dependencyName The stage that must run BEFORE $dependentName
		 * @throws \LogicException If either stage name is unknown
		 */
		public function addDependency(string $dependentName, string $dependencyName): void {
			if (!isset($this->stages[$dependentName])) {
				throw new \LogicException("Cannot add dependency: unknown stage '{$dependentName}'");
			}
			
			if (!isset($this->stages[$dependencyName])) {
				throw new \LogicException("Cannot add dependency: unknown stage '{$dependencyName}'");
			}
			
			// Avoid duplicate edges
			if (!in_array($dependencyName, $this->dependencies[$dependentName], true)) {
				$this->dependencies[$dependentName][] = $dependencyName;
			}
		}
		
		/**
		 * Returns all stages arranged in the correct execution order that respects dependencies.
		 * The order is critical to ensure that stages are executed only after their dependencies.
		 *
		 * Implements Kahn's algorithm (BFS-based topological sort):
		 *   1. Compute in-degree (number of unresolved dependencies) for each stage.
		 *   2. Enqueue all stages with in-degree 0 (no unsatisfied dependencies).
		 *   3. Repeatedly dequeue a stage, add it to the output, and decrement the
		 *      in-degree of all stages that depend on it. Enqueue any that reach 0.
		 *   4. If the output length is less than the stage count, a cycle exists.
		 *
		 * @return ExecutionStageInterface[] Array of stages in dependency-respecting execution order
		 * @throws QuelException If a dependency cycle is detected
		 */
		public function getStagesInOrder(): array {
			// in-degree[S] = number of stages that S depends on that have not yet been scheduled.
			// Stages with in-degree 0 have all dependencies satisfied and are ready to run.
			$inDegree = [];
			
			foreach ($this->stages as $stageName => $_) {
				$inDegree[$stageName] = count($this->dependencies[$stageName] ?? []);
			}
			
			// Seed the queue with all stages that have no dependencies
			$queue = [];
			
			foreach ($inDegree as $name => $degree) {
				if ($degree === 0) {
					$queue[] = $name;
				}
			}
			
			$sorted = [];
			
			while (!empty($queue)) {
				$current = array_shift($queue);
				$sorted[] = $this->stages[$current];
				
				// For every stage that lists $current as a dependency, decrement its
				// in-degree. When a stage's in-degree reaches 0, all its dependencies
				// have been scheduled and it is ready to run.
				foreach ($this->dependencies as $stageName => $deps) {
					if (in_array($current, $deps, true)) {
						$inDegree[$stageName]--;
						
						if ($inDegree[$stageName] === 0) {
							$queue[] = $stageName;
						}
					}
				}
			}
			
			// If we couldn't schedule all stages, there is a cycle in the dependency graph.
			// This indicates a programming error in plan construction, not a user query error.
			if (count($sorted) !== count($this->stages)) {
				throw new QuelException(
					'Circular dependency detected in execution plan stages'
				);
			}
			
			return $sorted;
		}
		
		/**
		 * Returns true if the execution plan is empty, false if not
		 * @return bool
		 */
		public function isEmpty(): bool {
			return empty($this->stages);
		}
		
		/**
		 * Returns the raw dependency map for debugging and testing.
		 * @return array<string, string[]>
		 */
		public function getDependencies(): array {
			return $this->dependencies;
		}
	}