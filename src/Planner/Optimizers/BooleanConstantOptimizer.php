<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Propagates boolean constants upward through the WHERE clause, collapsing
	 * expressions that can be resolved at compile time:
	 *
	 *   NOT(true) → false,    NOT(false) → true
	 *   true  AND x → x,      false AND x → false
	 *   true  OR  x → true,   false OR  x → x
	 *   expr = 1  → expr,     expr = 0   → NOT(expr)   (and symmetric)
	 *   expr <> 1 → NOT(expr), expr <> 0 → expr
	 *
	 * A WHERE clause that collapses entirely to true is removed from the query.
	 * One that collapses to false is kept so the database short-circuits to empty.
	 *
	 * Numeric literals 0 and 1 are treated as boolean constants. All other numeric
	 * literals are left unchanged.
	 */
	class BooleanConstantOptimizer {
		
		/**
		 * Propagates boolean constants through the WHERE clause.
		 * No-ops when there are no conditions.
		 * @param AstRetrieve $ast The query to rewrite in-place
		 */
		public function optimize(AstRetrieve $ast): void {
			$conditions = $ast->getConditions();
			
			if ($conditions === null) {
				return;
			}
			
			$simplified = $this->propagate($conditions);
			
			// A top-level true means the WHERE clause is unconditionally satisfied —
			// drop it entirely so no WHERE clause is emitted.
			if ($simplified instanceof AstBool && $simplified->getValue() === true) {
				$ast->setConditions(null);
			} else {
				$ast->setConditions($simplified);
			}
		}
		
		/**
		 * Recursively propagates AstBool constants upward through the condition tree,
		 * applying short-circuit and identity rules at each node type.
		 * Leaf nodes (identifiers, literals, parameters) are returned unchanged.
		 * @param AstInterface $node The condition subtree to simplify
		 * @return AstInterface The simplified subtree
		 */
		private function propagate(AstInterface $node): AstInterface {
			if ($node instanceof AstNot) {
				$inner = $this->propagate($node->getExpression());
				
				// NOT on a known constant resolves immediately
				if ($inner instanceof AstBool) {
					return new AstBool(!$inner->getValue());
				}
				
				$node->setExpression($inner);
				return $node;
			}
			
			if ($node instanceof AstBinaryOperator) {
				// Recurse first so constants from deeper nodes are visible here
				$left = $this->propagate($node->getLeft());
				$right = $this->propagate($node->getRight());
				
				$result = $this->foldBinaryOperator($node->getOperator(), $left, $right);
				
				if ($result !== null) {
					return $result;
				}
				
				// No rule fired — reattach the (possibly rewritten) children and continue
				$node->setLeft($left);
				$node->setRight($right);
				return $node;
			}
			
			if ($node instanceof AstExpression) {
				$left = $this->propagate($node->getLeft());
				$right = $this->propagate($node->getRight());
				
				$result = $this->foldComparison($node->getOperator(), $left, $right);
				
				if ($result !== null) {
					return $result;
				}
				
				$node->setLeft($left);
				$node->setRight($right);
				return $node;
			}
			
			// AstBool, AstIdentifier, AstNumber, AstString, AstParameter, etc.
			// — nothing to propagate, return as-is
			return $node;
		}
		
		/**
		 * Applies AND / OR short-circuit rules when one operand is a boolean constant.
		 * Returns null when neither operand is a constant and no rule can fire.
		 * @param string $operator 'AND' or 'OR'
		 * @param AstInterface $left Left operand (possibly already propagated)
		 * @param AstInterface $right Right operand (possibly already propagated)
		 * @return AstInterface|null Folded result, or null if no rule matched
		 */
		private function foldBinaryOperator(string $operator, AstInterface $left, AstInterface $right): ?AstInterface {
			// Decision table: for each operator, a boolean constant value maps to either
			// 'self' (identity — return the other operand) or a fixed result (annihilator).
			$table = [
				'AND' => [false => 'false', true => 'self'],
				'OR'  => [false => 'self',  true => 'true'],
			];
			
			// Unknown operator — no rule applies
			if (!isset($table[$operator])) {
				return null;
			}
			
			// Identify which side (if any) is the boolean constant
			if ($left instanceof AstBool) {
				[$constant, $other] = [$left, $right];
			} elseif ($right instanceof AstBool) {
				[$constant, $other] = [$right, $left];
			} else {
				return null;
			}
			
			return match ($table[$operator][$constant->getValue()]) {
				'self'  => $other,
				'true'  => new AstBool(true),
				'false' => new AstBool(false),
			};
		}
		
		/**
		 * Folds = / <> comparisons where one side is a boolean constant (or numeric
		 * literal 0 / 1, which are normalised to AstBool first).
		 * Returns null when neither side is a constant or the operator is not = / <>.
		 * @param string $operator '=' or '<>'
		 * @param AstInterface $left Left operand (possibly already propagated)
		 * @param AstInterface $right Right operand (possibly already propagated)
		 * @return AstInterface|null Folded result, or null if no rule matched
		 */
		private function foldComparison(string $operator, AstInterface $left, AstInterface $right): ?AstInterface {
			// Treat numeric 0 and 1 as boolean constants so the rules below apply
			// uniformly to both `is_float(x) = 0` and `is_float(x) = false`.
			$left = $this->normalizeBoolLiteral($left);
			$right = $this->normalizeBoolLiteral($right);
			
			// Check if any side is bool
			$leftIsBool = $left instanceof AstBool;
			$rightIsBool = $right instanceof AstBool;
			
			// Need exactly one constant side to apply a rule; two constants or none
			// means either a tautology/contradiction (handled elsewhere) or no-op.
			if (!$leftIsBool && !$rightIsBool) {
				return null;
			}
			
			// Canonicalize: put the constant on the right so the rules below only
			// need to handle one orientation instead of two.
			if ($leftIsBool && !$rightIsBool) {
				[$left, $right] = [$right, $left];
			}
			
			/** @var AstBool $right */
			$constValue = $right->getValue();

			// Do not fold comparisons like `p.published = true` when the non-constant
			// side is a plain column identifier. ConditionFilter only accepts
			// AstExpression leaves, so reducing to AstIdentifier drops the WHERE clause.
			// Folding is only safe for derived boolean expressions (e.g. is_float()).
			if ($left instanceof AstIdentifier) {
				return null;
			}
			
			// expr = true  → expr (identity)
			// expr = false → NOT(expr) (negation)
			if ($operator === '=') {
				return $constValue ? $left : new AstNot($left);
			}
			
			// expr <> true  → NOT(expr) (negation)
			// expr <> false → expr (double negation eliminates)
			if ($operator === '<>') {
				return $constValue ? new AstNot($left) : $left;
			}
			
			return null;
		}
		
		/**
		 * Converts AstNumber("0") → AstBool(false) and AstNumber("1") → AstBool(true).
		 * All other nodes pass through unchanged.
		 * @param AstInterface $node The node to normalise
		 * @return AstInterface The original node or its boolean equivalent
		 */
		private function normalizeBoolLiteral(AstInterface $node): AstInterface {
			if (!$node instanceof AstNumber) {
				return $node;
			}
			
			// Only 0 and 1 have an unambiguous boolean interpretation
			return match ($node->getValue()) {
				'0' => new AstBool(false),
				'1' => new AstBool(true),
				default => $node,
			};
		}
	}