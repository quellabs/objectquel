<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	/**
	 * Utilities for processing query results
	 */
	class ResultProcessor {
		
		/**
		 * Removes duplicate objects from an array based on their object hash.
		 * Non-objects in the array are left unchanged.
		 * @param array $array The input array with possibly duplicate objects.
		 * @return array An array with unique objects and all original non-object elements.
		 */
		public static function deDuplicateObjects(array $array): array {
			// Storage for the hashes of objects that have already been seen.
			$objectKeys = [];
			
			// Use array_filter to go through the array and remove duplicate objects.
			return array_filter($array, function ($item) use (&$objectKeys) {
				// If the item is not an object, keep it in the array.
				if (!is_object($item)) {
					return true;
				}
				
				// Calculate the unique hash of the object.
				$hash = spl_object_hash($item);
				
				// Check if the hash is already in the list of seen objects.
				if (in_array($hash, $objectKeys, true)) {
					// If yes, filter this object out of the array.
					return false;
				}
				
				// Add the hash to the list of seen objects and keep the item in the array.
				$objectKeys[] = $hash;
				return true;
			});
		}
	}