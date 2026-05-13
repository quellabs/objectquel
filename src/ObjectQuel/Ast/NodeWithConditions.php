<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for nodes that own a conditions (WHERE/FILTER) slot.
	 *
	 * Implemented by AstRetrieve (WHERE clause), AstAggregate (FILTER clause),
	 * and AstSubquery (subquery WHERE clause). Walkers that need to read or
	 * replace the conditions child use this interface instead of enumerating
	 * concrete types.
	 */
	interface NodeWithConditions extends AstInterface {
		
		/**
		 * Returns the conditions child, or null if there are none.
		 * @return AstInterface|null
		 */
		public function getConditions(): ?AstInterface;
		
		/**
		 * Replaces the conditions child.
		 * @param AstInterface|null $conditions
		 * @return void
		 */
		public function setConditions(?AstInterface $conditions): void;
	}