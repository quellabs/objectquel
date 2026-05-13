<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	/**
	 * No-op plan log used during normal (non-explain) query execution.
	 *
	 * All methods are empty so the optimizer pipeline incurs no overhead
	 * when plan logging is not needed.
	 */
	class NullPlanLog implements PlanLogInterface {
		
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
			// intentional no-op
		}
	}