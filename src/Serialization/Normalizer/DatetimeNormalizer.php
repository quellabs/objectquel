<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * DatetimeNormalizer handles conversion between database datetime strings and PHP DateTime objects.
	 *
	 * This class implements NormalizerInterface and provides functionality to:
	 * - Convert string datetime values from a database to PHP \DateTime objects (normalize)
	 * - Convert PHP \DateTime objects back to formatted strings for database storage (denormalize)
	 */
	class DatetimeNormalizer implements NormalizerInterface  {
		
		/**
		 * Parameters as passed by the annotation
		 * @var array
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
		 * Converts a string datetime to a PHP \DateTime object
		 * @param mixed $value
		 * @return \DateTime|null Returns a DateTime object or null if:
		 *                        - Input value is null
		 *                        - Input is an empty/zero datetime ("0000-00-00 00:00:00")
		 */
		public function normalize(mixed $value): ?\DateTime {
			// Return null for null values or empty/zero datetimes
			if (is_null($value) || $value == "0000-00-00 00:00:00") {
				return null;
			}
			
			// Convert string datetime to \DateTime object using the format "Y-m-d H:i:s"
			return \DateTime::createFromFormat("Y-m-d H:i:s", $value);
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
			return $value->format("Y-m-d H:i:s");
		}
	}