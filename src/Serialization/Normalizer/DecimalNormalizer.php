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
		 * The decimal value to be processed.
		 * @var mixed
		 */
		protected $value;
		
		/**
		 * Sets the value to normalize/denormalize
		 * @param mixed $value The decimal value to process
		 * @return void
		 */
		public function setValue($value): void {
			$this->value = $value;
		}
		
		/**
		 * Returns the decimal value unchanged.
		 * @return mixed The original value unchanged
		 */
		public function normalize(): mixed {
			return $this->value;
		}
		
		/**
		 * Returns the decimal value unchanged.
		 * @return mixed The original value unchanged
		 */
		public function denormalize(): mixed {
			return $this->value;
		}
	}