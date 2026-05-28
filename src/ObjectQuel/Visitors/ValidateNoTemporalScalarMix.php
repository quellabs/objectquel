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
			// Only arithmetic operators can mix temporal and scalar values.
			// Comparison operators (=, <, >, etc.) are handled separately and
			// legitimately allow comparing a timestamp column to an integer.
			if (!$node instanceof NodeBinary) {
				return;
			}
			
			$operator = $node->getOperator();
			
			if ($operator !== '+' && $operator !== '-') {
				return;
			}
			
			$leftType  = $this->resolveType->inferReturnType($node->getLeft());
			$rightType = $this->resolveType->inferReturnType($node->getRight());
			
			$leftIsTemporal  = $leftType  === 'datetime' || $leftType  === 'interval';
			$rightIsTemporal = $rightType === 'datetime' || $rightType === 'interval';
			
			// If neither side is temporal, this is plain scalar arithmetic — fine.
			if (!$leftIsTemporal && !$rightIsTemporal) {
				return;
			}
			
			// If one side is temporal and the other is not, that is a type error.
			if ($leftIsTemporal !== $rightIsTemporal) {
				$temporalSide = $leftIsTemporal  ? 'left'  : 'right';
				$scalarSide   = $rightIsTemporal ? 'left'  : 'right';
				$temporalType = $leftIsTemporal  ? $leftType : $rightType;
				$scalarType   = $leftIsTemporal  ? ($rightType ?? 'scalar') : ($leftType ?? 'scalar');
				
				throw new SemanticException(sprintf(
					"Type error: cannot use '%s' between a %s value and a plain %s. " .
					"Both operands of '%s' must be temporal (datetime or interval) when either one is. " .
					"Did you mean to wrap the %s side in date()?",
					$operator,
					$temporalType,
					$scalarType,
					$operator,
					$scalarSide
				));
			}
		}
	}