<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * Normalizer for PHP Enum types.
	 * Handles bidirectional conversion between enum instances and their scalar values.
	 */
	class EnumNormalizer implements NormalizerInterface {

		/**
		 * Fully qualified class name of the enum type to work with.
		 * Must be a BackedEnum implementation.
		 * @var string
		 */
		private string $enumType;
		
		/**
		 * Constructor - initializes the normalizer with enum type configuration.
		 *
		 * @param array $parameters Configuration array, must contain 'enumType' key
		 * @throws \RuntimeException If 'enumType' parameter is missing
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['enumType'])) {
				throw new \RuntimeException(
					"EnumNormalizer requires 'enumType' parameter"
				);
			}
			
			$this->enumType = $parameters['enumType'];
		}

		/**
		 * Normalize converts an enum instance to its scalar backing value.
		 * Used when preparing entity data for database storage.
		 * @param mixed $value
		 * @return mixed Returns the enum's backing value (string|int) if input is a BackedEnum,
		 *               otherwise returns the value unchanged. Returns null if value is null.
		 */
		public function normalize(mixed $value): mixed {
			if ($value instanceof \BackedEnum) {
				return $value->value;
			} else {
				return $value;
			}
		}
		
		/**
		 * Denormalize converts a scalar value to an enum instance.
		 * Used when hydrating entity properties from database values.
		 * @param mixed $value
		 * @return \BackedEnum|null Returns an enum instance created from the scalar value,
		 *                          or null if the input value is null.
		 * @throws \ValueError If the value doesn't correspond to any enum case
		 */
		public function denormalize(mixed $value): ?\BackedEnum {
			if ($value === null) {
				return null;
			}
			
			$enumClass = $this->enumType;
			return $enumClass::from($value);
		}
	}