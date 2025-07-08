<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	/**
	 * Class ResultTransformer
	 * Transforms query results based on specified criteria
	 * This class provides utility methods to manipulate query result sets
	 */
	class ResultTransformer {
		
		/**
		 * Sorts the results array based on provided sort criteria
		 * @param array &$results Reference to the array of results to be sorted (passed by reference for in-place sorting)
		 * @param array $sortItems Array of sort specifications, each containing AST nodes and order direction
		 * @return void This method modifies the input array directly and doesn't return a value
		 */
		public function sortResults(array &$results, array $sortItems): void {
			// Use PHP's usort function with a custom comparison callback
			usort($results, function ($a, $b) use ($sortItems) {
				// Iterate through each sort item in the specified order
				foreach ($sortItems as $sortItem) {
					// Extract the Abstract Syntax Tree node from the sort item
					$ast = $sortItem['ast'];
					
					// Extract the sort direction ('asc' or 'desc')
					$order = $sortItem['order'];
					
					// Get the parent identifier from the AST node
					// This likely represents the entity or field to sort by
					$entity = $ast->getParentIdentifier();
					
					// Get the range name from the entity
					// This represents the actual property/key in the result array to compare
					$range = $entity->getRange()->getName();
					
					// Compare the values of the current field in both items
					if ($a[$range] < $b[$range]) {
						// If the first item's value is less than the second's
						// For 'desc' order, return 1 (b comes before a)
						// For 'asc' order, return -1 (a comes before b)
						return $order === 'desc' ? 1 : -1;
					} elseif ($a[$range] > $b[$range]) {
						// If the first item's value is greater than the second's
						// For 'desc' order, return -1 (a comes before b)
						// For 'asc' order, return 1 (b comes before a)
						return $order === 'desc' ? -1 : 1;
					}
					
					// If values are equal, continue to the next sort criteria
					// This implements multi-level sorting
				}
				
				// If all sort criteria result in equality, items maintain their relative positions
				return 0;
			});
		}
	}