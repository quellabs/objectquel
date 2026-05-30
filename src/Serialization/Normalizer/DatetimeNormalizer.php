<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	use DateTime;
	
	/**
	 * DatetimeNormalizer handles conversion between database datetime strings and PHP DateTime objects.
	 *
	 * This class implements NormalizerInterface and provides functionality to:
	 * - Convert string datetime values from a database to PHP \DateTime objects (normalize)
	 * - Convert PHP \DateTime objects back to formatted strings for database storage (denormalize)
	 */
	class DatetimeNormalizer implements NormalizerInterface {
		
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
		 * Converts a database datetime value to a PHP \DateTime object.
		 *
		 * Accepts two input forms:
		 *   - A string in "Y-m-d H:i:s" format (normal column hydration)
		 *   - An integer or numeric string Unix timestamp, produced when a
		 *     date() expression appears in the SELECT list
		 *
		 * @param mixed $value
		 * @return DateTime|null Returns a DateTime object or null if:
		 *                        - Input value is null
		 *                        - Input is an empty/zero datetime ("0000-00-00 00:00:00")
		 *                        - Input cannot be parsed
		 * @throws \DateInvalidTimeZoneException|\DateMalformedStringException
		 */
		public function normalize(mixed $value): ?DateTime {
			// Fetch timezone
			$timezone = new \DateTimeZone(date_default_timezone_get());
			
			// Unix timestamp integer returned by UNIX_TIMESTAMP() / strftime('%s') /
			// EXTRACT(EPOCH FROM …) when a date() expression is in the SELECT list.
			if (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-')))) {
				// If value is 0, return null
				if ((int)$value === 0) {
					return null;
				}
				
				// Convert to datetime
				$date = new DateTime('@' . (int)$value);
				$date->setTimezone($timezone);
				return $date;
			}
			
			// Value has to be a string for the standard "Y-m-d H:i:s" path
			if (!is_string($value)) {
				return null;
			}
			
			// Return null for empty/zero datetimes
			if ($value === "0000-00-00 00:00:00") {
				return null;
			}
			
			// Accept "Y-m-d H:i:s" — the standard database datetime column format.
			$date = DateTime::createFromFormat("Y-m-d H:i:s", $value, $timezone);
			return $date !== false ? $date : null;
		}
		
		/**
		 * Converts a PHP \DateTime object to a formatted datetime string
		 * @param mixed $value
		 * @return string|null Returns a formatted datetime string in "Y-m-d H:i:s" format
		 *                     or null if the input \DateTime is null
		 */
		public function denormalize(mixed $value): ?string {
			// Return null if the DateTime object is null
			if ($value === null) {
				return null;
			}
			
			// Format the DateTime object to a string using the format "Y-m-d H:i:s"
			return ($value instanceof DateTime) ? $value->format("Y-m-d H:i:s") : null;
		}
	}