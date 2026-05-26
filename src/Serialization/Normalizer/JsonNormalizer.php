<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * Normalizer for JSON-typed columns.
	 * Handles bidirectional conversion between the raw JSON strings stored in the
	 * database and the decoded PHP arrays (or scalars) used in application code.
	 */
	class JsonNormalizer implements NormalizerInterface {
		
		/**
		 * @param array<string, mixed> $parameters Column annotation parameters (unused for JSON).
		 */
		public function __construct(array $parameters) {
		}
		
		/**
		 * Converts a raw JSON string from the database into a PHP value.
		 *
		 * Called during entity hydration. The returned value is assigned to the
		 * entity property, so it will be an associative array for JSON objects,
		 * an indexed array for JSON arrays, or a scalar for JSON primitives.
		 * @param mixed $value The raw database value, expected to be a JSON string or null.
		 * @return mixed Decoded PHP value, or null when the input is null.
		 */
		public function normalize(mixed $value): mixed {
			if ($value === null) {
				return null;
			}
			
			return json_decode($value, true);
		}
		
		/**
		 * Converts a PHP value into a JSON string for database storage.
		 *
		 * Called during entity persistence. Returns null both when the input is null
		 * and when encoding fails, so the database receives NULL rather than a
		 * corrupt or partial string.
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