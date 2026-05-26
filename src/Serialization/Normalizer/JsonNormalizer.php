<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * Normalizer for JSON-typed columns.
	 *
	 * Handles bidirectional conversion between the raw JSON strings stored in the
	 * database and the decoded PHP arrays (or scalars) used in application code.
	 *
	 * Registered automatically by the Serializer's normalizer autodiscovery because
	 * the file name follows the {Type}Normalizer.php convention. It is invoked for
	 * any entity property whose @Column annotation carries type="json".
	 */
	class JsonNormalizer implements NormalizerInterface {
		
		/**
		 * @param array<string, mixed> $parameters Column annotation parameters (unused for JSON).
		 */
		public function __construct(array $parameters) {
		}
		
		/**
		 * Converts a raw JSON string from the database into a PHP value.
		 * @param mixed $value The raw database value, expected to be a JSON string or null.
		 * @return mixed Decoded PHP value, or null when the input is null.
		 */
		public function normalize(mixed $value): mixed {
			if ($value === null) {
				return null;
			}
			
			if (!is_string($value)) {
				return $value;
			}
			
			return json_decode($value, true);
		}
		
		/**
		 * Converts a PHP value into a JSON string for database storage.
		 * @param mixed $value The PHP value to encode (array, scalar, or null).
		 * @return string|null JSON-encoded string, or null if the input is null or unserializable.
		 */
		public function denormalize(mixed $value): string|null {
			if ($value === null) {
				return null;
			}
			
			try {
				return json_encode($value, JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				return null;
			}
		}
	}