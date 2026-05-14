<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Represents an unresolved search() expression in the ObjectQuel AST.
	 *
	 * This is the form produced by the parser. SearchStrategyResolver later
	 * replaces it with AstSearchLike or AstSearchFullText depending on whether
	 * a FullTextIndex covers the searched columns.
	 */
	class AstSearch extends NodeSearch {
		
		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone());
		}
	}