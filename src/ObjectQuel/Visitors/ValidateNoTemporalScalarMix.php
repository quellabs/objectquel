<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Validates that temporal values (datetime, interval) are never mixed with
	 * plain scalars in arithmetic expressions.
	 *
	 * QUEL treats date() as a first-class type. The only valid operand combinations
	 * for + and - involving a temporal value are those in the type table:
	 *
	 *   datetime ± interval  → datetime
	 *   interval ± interval  → interval
	 *   datetime - datetime  → interval
	 *
	 * Mixing a temporal value with a plain scalar (e.g. date("6 days") + 1) is a
	 * type error. It has no defined meaning and must be caught here rather than
	 * silently producing a wrong result or a confusing SQL error.
	 */
	class ValidateNoTemporalScalarMix implements AstVisitorInterface {
		
		private ResolveType $resolveType;
		
		/**
		 * @param EntityStore $entityStore Required by ResolveType to look up column annotations
		 */
		public function __construct(EntityStore $entityStore) {
			$this->resolveType = new ResolveType($entityStore);
		}
		
		/**
		 * @param AstInterface $node
		 * @return void
		 * @throws SemanticException
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only binary nodes can produce a type mismatch — skip everything else.
			if (!$node instanceof NodeBinary) {
				return;
			}
			
			$operator = $node->getOperator();
			
			// Only + and - are temporal arithmetic operators. Comparison operators
			// (=, <, >, etc.) legitimately accept a date() value on one side and a
			// plain integer on the other — e.g. date(p.createdAt) > :cutoff where
			// :cutoff is a raw Unix timestamp. Those are not our concern here.
			if ($operator !== '+' && $operator !== '-') {
				return;
			}
			
			// Infer the return type of each operand by walking its subtree.
			// AstDate nodes declare 'datetime' or 'interval'; everything else
			// declares 'int', 'float', 'string', etc.
			$leftType = $this->resolveType->inferReturnType($node->getLeft());
			$rightType = $this->resolveType->inferReturnType($node->getRight());
			
			$leftIsTemporal = $leftType === 'datetime' || $leftType === 'interval';
			$rightIsTemporal = $rightType === 'datetime' || $rightType === 'interval';
			
			// Neither operand is temporal — plain scalar arithmetic, nothing to validate.
			if (!$leftIsTemporal && !$rightIsTemporal) {
				return;
			}
			
			// Both operands are temporal — valid combination, type table handles the rest.
			if ($leftIsTemporal && $rightIsTemporal) {
				return;
			}

			// One temporal, one scalar — tell the user which side needs wrapping.
				$scalarSide = $rightIsTemporal ? 'left' : 'right';
				
				throw new SemanticException(sprintf(
					"Type error: cannot use '%s' between a date() value and a plain scalar. " .
					"Both operands of '%s' must be date() values when either one is. " .
					"Did you mean to wrap the %s side in date()?",
					$operator,
					$operator,
					$scalarSide
				));
			}
		}