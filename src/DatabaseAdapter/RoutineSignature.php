<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	/**
	 * What the database catalog says about a routine: its kind and, for a function, its return type.
	 */
	final readonly class RoutineSignature {

		/**
		 * Describes a routine's kind and normalized return type.
		 * @param bool $isProcedure True for a procedure, false for a function
		 * @param string|null $returnType Abstract column type the function returns, or null for a procedure or an unrecognized type
		 * @return void
		 */
		public function __construct(
			public bool $isProcedure,
			public ?string $returnType,
		) {
		}
	}
