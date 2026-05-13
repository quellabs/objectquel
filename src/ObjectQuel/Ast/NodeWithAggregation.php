<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for nodes that own an aggregation slot.
	 *
	 * Implemented by AstSubquery. Walkers that need to read or replace
	 * the aggregation child use this interface instead of checking the
	 * concrete type.
	 */
	interface NodeWithAggregation extends AstInterface {
		
		/**
		 * Returns the aggregation child, or null if there is none.
		 * @return AstInterface|null
		 */
		public function getAggregation(): ?AstInterface;
		
		/**
		 * Replaces the aggregation child.
		 * @param AstInterface $aggregation
		 * @return void
		 */
		public function setAggregation(AstInterface $aggregation): void;
	}