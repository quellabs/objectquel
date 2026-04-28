<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	/**
	 * Value object carrying condition analysis signals for a single range.
	 * Using named arguments in the constructor keeps instantiation readable
	 * without needing a separate factory or builder.
	 */
	readonly class RangeConditionAnalysis {
		
		public function __construct(
			/** True if the WHERE clause contains explicit IS NULL / IS NOT NULL checks for this range */
			public bool $hasNullChecks,
			
			/** True if the WHERE clause references any field from this range */
			public bool $hasFieldReferences,
			
			/** True if WHERE references a non-nullable field, meaning NULL rows from this join are already filtered out */
			public bool $eliminatesNulls,
		) {
		}
	}