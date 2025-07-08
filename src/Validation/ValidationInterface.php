<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	interface ValidationInterface {
		
		/**
		 * ValidationInterface constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions=[]);
		
		/**
		 * The value to validate
		 * @param $value
		 * @return bool
		 */
		public function validate($value) : bool;
		
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
	