<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for full-text search nodes that operate over a list
	 * of column identifiers.
	 *
	 * Implemented by AstSearch and AstSearchScore. Walkers use this interface to
	 * iterate over the searched columns without enumerating both concrete types.
	 */
	interface NodeSearch {
		
		/**
		 * Returns the list of column identifiers this search operates over.
		 * @return AstInterface[]
		 */
		public function getIdentifiers(): array;
	}