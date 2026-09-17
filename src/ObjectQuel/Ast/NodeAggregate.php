<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for aggregate function nodes that operate over an identifier.
	 *
	 * Implemented by AstAggregate (the common base for COUNT, COUNT UNIQUE, AVG,
	 * AVG UNIQUE, MAX, MIN, SUM, SUM UNIQUE, ANY). Walkers use this interface to
	 * recurse into the aggregate's target identifier without enumerating every
	 * concrete aggregate type.
	 */
	interface NodeAggregate extends AstInterface {

		/**
		 * Returns the identifier (column reference) this aggregate operates over.
		 * Null for sequence functions with no value argument (rank, dense_rank, row_number).
		 * @return AstInterface|null
		 */
		public function getIdentifier(): ?AstInterface;
		
		/**
		 * Replaces the identifier.
		 * @param AstInterface $identifier
		 * @return void
		 */
		public function setIdentifier(AstInterface $identifier): void;
	}