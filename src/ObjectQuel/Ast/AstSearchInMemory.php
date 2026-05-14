<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Represents a search() condition evaluated entirely in memory.
	 *
	 * Produced by SearchStrategyResolver when the searched identifiers belong to a
	 * non-database range (JSON source, temp table, cross-join). No SQL is generated
	 * for this node; ConditionEvaluator handles it directly using term matching
	 * against the in-memory row data.
	 */
	class AstSearchInMemory extends NodeSearch {
		
		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone());
		}
	}