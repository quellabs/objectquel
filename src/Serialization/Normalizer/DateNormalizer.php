<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * DateNormalizer handles conversion between database date strings and PHP DateTime objects.
	 *
	 * Accepts two input forms:
	 *   - A string in "Y-m-d" format (normal DATE column hydration)
	 *   - An integer or numeric string Unix timestamp, produced when a
	 *     date() expression appears in the SELECT list
	 */
	class DateNormalizer implements NormalizerInterface {

		/**
		 * Parameters as passed by the annotation
		 * @var array<string, mixed>
		 * @phpstan-ignore-next-line property.onlyWritten
         */
		private array $parameters;
		
		/**
		 * Passed annotation parameters
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Converts a database date value to a PHP \DateTime object.
		 *
		 * @param mixed $value
		 * @return \DateTime|null Returns a DateTime object or null if:
		 *                        - Input value is null
		 *                        - Input is an empty/zero date ("0000-00-00")
		 *                        - Input cannot be parsed
		 * @throws \DateInvalidTimeZoneException
		 */
		public function normalize(mixed $value): ?\DateTime {
			$timezone = new \DateTimeZone(date_default_timezone_get());

			// Unix timestamp integer — produced when a date() expression appears
			// in the SELECT list. Mirrors the same path in DatetimeNormalizer.
			if (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-')))) {
				if ((int)$value === 0) {
					return null;
				}

				$date = new \DateTime('@' . (int)$value);
				$date->setTimezone($timezone);
				return $date;
			}

			// Value must be a string for the "Y-m-d" path
			if (!is_string($value)) {
				return null;
			}
			
			// Return null for empty/zero dates
			if ($value === "0000-00-00") {
				return null;
			}
			
			// Convert string date to \DateTime using the "Y-m-d" format
			$date = \DateTime::createFromFormat("Y-m-d", $value, $timezone);
			return $date !== false ? $date : null;
		}
		
		/**
		 * Converts a PHP \DateTime object to a "Y-m-d" date string for database storage.
		 * @param mixed $value
		 * @return string|null
		 */
		public function denormalize(mixed $value): ?string {
			if ($value === null) {
				return null;
			}
			
			return ($value instanceof \DateTime) ? $value->format("Y-m-d") : null;
		}
	}