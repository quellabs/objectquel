<?php

	namespace Quellabs\ObjectQuel\Persistence;

	/**
	 * A piece of generated Quel text (SET assignments, WHERE conditions, ...)
	 * paired with the parameters it binds. Keeps the two in a single named
	 * value instead of parallel arrays or out-parameters.
	 */
	final class QuelFragment {

		/**
		 * @param array<int, string> $clauses
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(
			public readonly array $clauses,
			public readonly array $parameters,
		) {
		}
	}
