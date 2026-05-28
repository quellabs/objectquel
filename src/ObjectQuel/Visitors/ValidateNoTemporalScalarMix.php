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
	 * Validates arithmetic type rules for date() values.
	 *
	 * Two rules are enforced:
	 *
	 * 1. Mixing with scalars is forbidden on + and -.
	 *    QUEL treats date() as a first-class type. The only valid operand
	 *    combinations for + and - involving a temporal value are:
	 *
	 *   datetime ± interval  → datetime
	 *   interval ± interval  → interval
	 *   datetime - datetime  → interval
	 *
	 *    Mixing a temporal value with a plain scalar (e.g. date("6 days") + 1)
	 *    has no defined meaning and is rejected here.
	 *
	 * 2. Multiplication and division of temporal values are forbidden entirely.
	 *    date("6 days") * 2 and date("now") / 3 have no defined meaning and
	 *    are rejected regardless of what the other operand is.
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
			// Only binary nodes can produce a type error — skip everything else.
			if (!$node instanceof NodeBinary) {
				return;
			}
			
			// Fetch the operator
			$operator = $node->getOperator();
			
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

			// Multiplication and division of temporal values are never valid,
			// regardless of what the other operand is.
			if ($operator === '*' || $operator === '/') {
				throw new SemanticException(sprintf(
					"Type error: cannot use '%s' with a date() value. " .
					"Multiplication and division are not defined for date() values.",
					$operator
				));
			}

			// For + and -, both operands must be temporal — comparison operators
			// (=, <, >, etc.) legitimately accept a date() value on one side and a
			// plain integer on the other (e.g. date(p.createdAt) > :cutoff) and
			// are not checked here.
			if ($operator !== '+' && $operator !== '-') {
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