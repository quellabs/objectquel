<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	/**
	 * What the database catalog says about a routine: its kind and, for a function, its return type.
	 */
	final readonly class RoutineSignature {

		/**
		 * @param bool $isProcedure True for a procedure, false for a function
		 * @param string|null $returnType Abstract column type the function returns, or null for a procedure or an unrecognized type
		 */
		public function __construct(
			public bool $isProcedure,
			public ?string $returnType,
		) {
		}
	}
