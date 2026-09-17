<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Mapper;

	/**
	 * A parsed (precision, scale) pair for a 'decimal' column, e.g.
	 * "DECIMAL(10,2)" -> precision 10, scale 2. Both null when the source
	 * declaration carries neither (an unrecognized/absent match). Returned by
	 * the per-engine schema introspectors' own precision/scale parsers in
	 * place of a `[$precision, $scale] = ...` tuple destructure.
	 */
	final readonly class NumericPrecisionScale {

		/**
		 * @var int|null
		 */
		public ?int $precision;

		/**
		 * @var int|null
		 */
		public ?int $scale;

		/**
		 * @param int|null $precision
		 * @param int|null $scale
		 */
		public function __construct(?int $precision, ?int $scale) {
			$this->precision = $precision;
			$this->scale = $scale;
		}
	}
