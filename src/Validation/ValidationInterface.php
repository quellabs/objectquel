<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	interface ValidationInterface {
		
		/**
		 * ValidationInterface constructor
		 * @param array $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions=[], ?string $errorMessage = null);
		
		/**
		 * The value to validate
		 * @param mixed  $value
		 * @return bool
		 */
		public function validate(mixed $value) : bool;
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array;
		
		/**
		 * Return this error when validation failed
		 * @return string
		 */
		public function getError() : string;
		
	}
	