<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Represents a search_score() function call in the ObjectQuel AST.
	 *
	 * Used in SELECT and ORDER BY clauses to retrieve the relevance score of a
	 * full-text search as a numeric value. Requires a FullTextIndex annotation on
	 * the searched columns; an exception is thrown at SQL generation time if none
	 * is found.
	 *
	 * Usage:
	 *   retrieve (p, search_score(p.name, p.description, :term) as score)
	 *   where search(p.name, p.description, :term)
	 *   sort by score desc
	 *
	 * Emits: MATCH(`range`.`col1`, `range`.`col2`) AGAINST(:term IN BOOLEAN MODE)
	 */
	class AstSearchScore extends NodeSearch {
		
		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone());
		}
	}