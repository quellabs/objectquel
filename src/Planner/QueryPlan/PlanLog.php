<?php
	
	namespace Quellabs\ObjectQuel\Planner\QueryPlan;
	
	/**
	 * Records planning decisions for later inspection.
	 *
	 * Pass to QueryOptimizer::transform() and ExecutionPlanBuilder::build()
	 * when you want an explain-style view of what the planner decided.
	 */
	class PlanLog implements PlanLogInterface {
		
		/** @var PlanNote[] */
		private array $notes = [];
		
		/**
		 * @inheritDoc
		 */
		public function note(
			string  $source,
			string  $category,
			string  $decision,
			string  $reason,
			?string $subject = null
		): void {
			$this->notes[] = new PlanNote($source, $category, $decision, $reason, $subject);
		}
		
		/**
		 * Returns all recorded notes in the order they were added.
		 * @return PlanNote[]
		 */
		public function getNotes(): array {
			return $this->notes;
		}

		/**
		 * @return array<int, PlanNote>
		 */
		public function jsonSerialize(): array {
			return $this->notes;
		}
	}