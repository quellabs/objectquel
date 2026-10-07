<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	/**
	 * What the database catalog says about a routine: its kind, return type, and call requirements.
	 */
	final readonly class RoutineSignature {

		/**
		 * Describes a routine's kind and normalized return type.
		 * @param bool $isProcedure True for a procedure, false for a function
		 * @param string|null $returnType Abstract column type the function returns, or null for a procedure or an unrecognized type
		 * @param bool $needsTransaction Whether a generated MySQL/MariaDB procedure needs a transaction around the call
		 * @param bool $isTrigger Whether the routine is declared `trigger` (see RoutineMetadata); callable only through an event attachment, never an ordinary call
		 * @return void
		 */
		public function __construct(
			public bool $isProcedure,
			public ?string $returnType,
			public bool $needsTransaction = false,
			public bool $isTrigger = false,
		) {
		}
	}
