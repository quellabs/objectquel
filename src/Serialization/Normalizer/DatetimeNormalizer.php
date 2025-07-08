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
		 * The value to be normalized or denormalized.
		 * Can be either a string datetime or a \DateTime object.
		 * @var mixed
		 */
		protected $value;
		
		/**
		 * Sets the value to normalize/denormalize
		 * @param mixed $value Either a datetime string or a \DateTime object
		 * @return void
		 */
		public function setValue($value): void {
			$this->value = $value;
		}
		
		/**
		 * Converts a string datetime to a PHP \DateTime object
		 * @return \DateTime|null Returns a DateTime object or null if:
		 *                        - Input value is null
		 *                        - Input is an empty/zero datetime ("0000-00-00 00:00:00")
		 */
		public function normalize(): ?\DateTime {
			// Return null for null values or empty/zero datetimes
			if (is_null($this->value) || $this->value == "0000-00-00 00:00:00") {
				return null;
			}
			
			// Convert string datetime to \DateTime object using the format "Y-m-d H:i:s"
			return \DateTime::createFromFormat("Y-m-d H:i:s", $this->value);
		}
		
		/**
		 * Converts a PHP \DateTime object to a formatted datetime string
		 * @return string|null Returns a formatted datetime string in "Y-m-d H:i:s" format
		 *                     or null if the input \DateTime is null
		 */
		public function denormalize(): ?string {
			// Return null if the DateTime object is null
			if ($this->value === null) {
				return null;
			}
			
			// Format the DateTime object to a string using the format "Y-m-d H:i:s"
			return $this->value->format("Y-m-d H:i:s");
		}
	}