<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	/**
	 * Receives planning decisions as they are made.
	 *
	 * Pass a PlanLog to QueryOptimizer::transform() and ExecutionPlanBuilder::build()
	 * to collect notes. Pass a NullPlanLog to disable collection with no overhead.
	 */
	interface PlanLogInterface {
		
		/**
		 * Records a planning decision.
		 * @param string $source 'optimizer' or 'planner'
		 * @param string $category 'join', 'aggregate', 'search', 'stage', 'fold'
		 * @param string $decision Short label, e.g. 'LEFT_TO_INNER'
		 * @param string $reason Human-readable explanation
		 * @param string|null $subject Range name, field, or alias the decision applies to
		 */
		public function note(
			string  $source,
			string  $category,
			string  $decision,
			string  $reason,
			?string $subject = null
		): void;
	}