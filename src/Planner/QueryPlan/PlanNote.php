<?php
	
	namespace Quellabs\ObjectQuel\Planner\QueryPlan;
	
	/**
	 * Represents a single decision made during query planning.
	 *
	 * Each note records what was decided, why, and which part of the query
	 * it applies to. Notes are produced by optimizers and collected by a
	 * PlanLog for later inspection.
	 */
	readonly class PlanNote implements \JsonSerializable {
		
		/**
		 * @param string $source Where the decision was made: 'optimizer' or 'planner'
		 * @param string $category Broad decision type: 'join', 'aggregate', 'search', 'stage', 'fold'
		 * @param string $decision Short decision label, e.g. 'LEFT_TO_INNER', 'FULLTEXT', 'TEMP_TABLE'
		 * @param string $reason Human-readable explanation of why this decision was made
		 * @param string|null $subject The range name, field, or alias the decision applies to, if any
		 */
		public function __construct(
			public string  $source,
			public string  $category,
			public string  $decision,
			public string  $reason,
			public ?string $subject = null,
		) {}

		/**
		 * @return array<string, string|null>
		 */
		public function jsonSerialize(): array {
			return [
				'source'   => $this->source,
				'category' => $this->category,
				'decision' => $this->decision,
				'reason'   => $this->reason,
				'subject'  => $this->subject,
			];
		}
	}