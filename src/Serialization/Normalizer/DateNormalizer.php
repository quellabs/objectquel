<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * DateNormalizer handles conversion between database date strings and PHP DateTime objects.
	 *
	 * This class implements NormalizerInterface and provides functionality specifically for date
	 * values (without time components), converting between:
	 * - Database date strings in "Y-m-d" format
	 * - PHP \DateTime objects
	 *
	 * Note: Unlike DatetimeNormalizer which handles full datetime values, this class only deals
	 * with date values in the format "Y-m-d".
	 */
	class DateNormalizer implements NormalizerInterface {

		/**
		 * Parameters as passed by the annotation
		 * @var array
		 * @phpstan-ignore-next-line property.onlyWritten
         */
		private array $parameters;
		
		/**
		 * Passed annotation parameters
		 * @param array $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Normalize converts a value in the database into something that can be inserted into the entity.
		 * Specifically converts a date string to a \DateTime object.
		 *
		 * @return \DateTime|null Returns a DateTime object or null if:
		 *                        - Input value is null
		 *                        - Input is an empty/zero date ("0000-00-00")
		 */
		public function normalize(mixed $value): ?\DateTime {
			// Return null for null values or empty/zero dates
			if (is_null($value) || $value === "0000-00-00") {
				return null;
			}
			
			// Convert string date to \DateTime object using the format "Y-m-d"
			return \DateTime::createFromFormat("Y-m-d", $value);
		}
		
		/**
		 * Denormalize converts a value in an entity into something that can be inserted into the DB.
		 * Specifically converts a \DateTime object to a date string.
		 * @return string|null A formatted date string in "Y-m-d" format, or null if the input is null
		 */
		public function denormalize(mixed $value): ?string {
			// Return null if the DateTime object is null
			if ($value === null) {
				return null;
			}
			
			// Format the DateTime object to a string using only the date part in "Y-m-d" format
			return $value->format("Y-m-d");
		}
	}