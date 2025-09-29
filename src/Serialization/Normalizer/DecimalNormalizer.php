<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	/**
	 * DecimalNormalizer handles decimal values during serialization/deserialization.
	 *
	 * This class implements NormalizerInterface and provides a pass-through implementation
	 * for decimal values. Unlike other normalizers that transform data, this normalizer
	 * returns values unchanged, suggesting it may be intended as a placeholder or to
	 * explicitly indicate that decimal values should be used as-is.
	 */
	class DecimalNormalizer implements NormalizerInterface  {

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
		 * Returns the decimal value unchanged.
		 * @param mixed $value
		 * @return mixed The original value unchanged
		 */
		public function normalize(mixed $value): mixed {
			return $value;
		}
		
		/**
		 * Returns the decimal value unchanged.
		 * @param mixed $value
		 * @return mixed The original value unchanged
		 */
		public function denormalize(mixed $value): mixed {
			return $value;
		}
	}