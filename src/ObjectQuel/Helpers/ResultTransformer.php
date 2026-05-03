<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Class ResultTransformer
	 * Transforms query results based on specified criteria
	 * This class provides utility methods to manipulate query result sets
	 */
	class ResultTransformer {
		
		/**
		 * Sorts the results array based on provided sort criteria.
		 * Sort items are taken directly from AstRetrieve::getSort(), so the
		 * key is 'direction' (optional) rather than 'order'.
		 * AST traversal is precomputed once before the comparator runs to
		 * avoid O(n log n) repeated traversal.
		 * @param array<int, array<string, mixed>> $results Reference to the array of results to be sorted
		 * @param array<int, array{ast: AstInterface, direction?: string}> $sortItems Array of sort specifications from AstRetrieve::getSort()
		 * @return void This method modifies the input array directly and doesn't return a value
		 * @throws \UnexpectedValueException If a sort key resolves to a non-scalar value
		 */
		public function sortResults(array &$results, array $sortItems): void {
			// Precompute range names and normalized directions once.
			// Doing this inside the comparator would repeat AST traversal
			// O(n log n) times, which is expensive on large result sets.
			$normalizedSort = array_map(
				static function (array $item): array {
					$ast = $item['ast'];
					
					if (!$ast instanceof AstIdentifier) {
						throw new \UnexpectedValueException(
							'Sort AST must be an AstIdentifier'
						);
					}
					
					return [
						'range'     => $ast->getSourceRangeName(),
						'direction' => strtolower($item['direction'] ?? 'asc'),
					];
				},
				$sortItems
			);
			
			usort($results, static function (array $a, array $b) use ($normalizedSort): int {
				foreach ($normalizedSort as $sortItem) {
					$range = $sortItem['range'];
					
					// Use null-coalescing to avoid undefined index notices when the
					// hydrated result row does not contain this key
					$valueA = $a[$range] ?? null;
					$valueB = $b[$range] ?? null;
					
					// Equal values (including both null) fall through to the next
					// sort criterion, implementing stable multi-level sorting
					if ($valueA === $valueB) {
						continue;
					}
					
					// Null on one side only: treat null as less than any scalar so
					// that null-bearing rows sort consistently to the front (asc) or
					// back (desc) rather than producing undefined behavior
					if ($valueA === null || $valueB === null) {
						$nullFirst = ($valueA === null) ? -1 : 1;
						return $sortItem['direction'] === 'desc' ? -$nullFirst : $nullFirst;
					}
					
					// Non-scalar values in a sort position are a programming error:
					// silently continuing would produce incorrect, invisible results.
					// Throw so the problem is surfaced at the call site instead.
					if (!is_scalar($valueA) || !is_scalar($valueB)) {
						throw new \UnexpectedValueException(
							sprintf(
								"Sort key '%s' contains a non-scalar value (%s vs %s); only scalar values can be compared",
								$range,
								get_debug_type($valueA),
								get_debug_type($valueB)
							)
						);
					}
					
					if ($valueA < $valueB) {
						return $sortItem['direction'] === 'desc' ? 1 : -1;
					}
					
					return $sortItem['direction'] === 'desc' ? -1 : 1;
				}
				
				// All criteria were equal; maintain original relative order
				return 0;
			});
		}
	}