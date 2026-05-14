<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Represents a search() condition rendered as a MATCH...AGAINST expression.
	 *
	 * Produced by SearchStrategyResolver when a FullTextIndex covers all searched
	 * columns on the same entity. The raw search string is passed directly to
	 * AGAINST in boolean mode so MySQL's parser handles +/- prefixes natively.
		 */
	class AstSearchFullText extends NodeSearch {
		
		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone());
		}
	}