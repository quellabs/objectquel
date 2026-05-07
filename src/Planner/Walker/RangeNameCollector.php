<?php
	
	namespace Quellabs\ObjectQuel\Planner\Walker;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Collects the names of all temp ranges referenced anywhere in the walked subtree.
	 * Always visits both children of binary nodes — no short-circuit, because every
	 * matching range name must be gathered regardless of what was found elsewhere.
	 *
	 * Used by ConditionAnalyzer::findTempRangeDependencies() to build the
	 * inter-temp-range dependency graph for ExecutionPlanBuilder.
	 *
	 * @extends AstWalker<string[]>
	 */
	class RangeNameCollector extends AstWalker {
		
		/**
		 * @param string[] $tempRangeNames The closed set of temp range names to match against.
		 *                                 Only ranges whose names appear in this list will be collected.
		 */
		public function __construct(private readonly array $tempRangeNames) {}
		
		/**
		 * Returns a single-element array containing the identifier's range name if it
		 * is one of the tracked temp ranges, or an empty array if it is not.
		 * Strict comparison is used to avoid false positives from numeric-looking names.
		 * @param AstIdentifier $node The identifier node being visited
		 * @return string[] Zero or one range name
		 */
		protected function visitIdentifier(AstIdentifier $node): array {
			$range = $node->getRange();
			
			if ($range !== null && in_array($range->getName(), $this->tempRangeNames, strict: true)) {
				return [$range->getName()];
			}
			
			return [];
		}
		
		/**
		 * Returns an empty array for literals, parameters, and any other terminal node
		 * type, since they contain no range references to collect.
		 * @param AstInterface $node The unrecognised or terminal node
		 * @return string[] Always an empty array
		 */
		protected function visitDefault(AstInterface $node): array {
			return [];
		}
		
		/**
		 * Concatenates the results from both children. No short-circuit is applied
		 * because all matching range names across the entire subtree must be gathered.
		 * Deduplication is deferred to array_unique() in findTempRangeDependencies()
		 * to avoid repeated work during traversal.
		 * @param mixed $left string[] result from the left child (or prior accumulator)
		 * @param mixed $right string[] result from the right child (or current item)
		 * @return string[] All collected range names from both sides combined
		 */
		protected function mergeBinary(mixed $left, mixed $right): array {
			return array_merge($left, $right);
		}
	}