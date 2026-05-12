<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Replaces is_float(), is_integer(), and is_numeric() nodes in the WHERE clause
	 * with AstBool(true/false) when the argument type is statically known from entity
	 * metadata. Nodes whose type cannot be determined are left untouched so they fall
	 * through to runtime REGEXP evaluation as before.
	 *
	 * This pass only folds — it does not simplify the boolean constants it introduces.
	 * Run BooleanConstantOptimizer afterwards to collapse them through AND / OR / NOT.
	 */
	class TypeCheckFoldingOptimizer {
		
		/** @var ResolveType Type inference helper backed by entity metadata */
		private ResolveType $typeInference;
		
		/**
		 * @param EntityManager $entityManager Provides entity metadata for type inference
		 */
		public function __construct(EntityManager $entityManager) {
			$this->typeInference = new ResolveType($entityManager->getEntityStore());
		}
		
		/**
		 * Walks the WHERE clause and replaces statically-resolvable type-check nodes
		 * with boolean constants. No-ops when there are no conditions.
		 * @param AstRetrieve $ast The query to rewrite in-place
		 * @throws EntityResolutionException
		 */
		public function optimize(AstRetrieve $ast): void {
			$conditions = $ast->getConditions();
			
			if ($conditions === null) {
				return;
			}
			
			$ast->setConditions($this->fold($conditions));
		}
		
		/**
		 * Recursively replaces type-check nodes with AstBool when their argument
		 * type is statically known. Returns the (possibly rewritten) subtree.
		 * @param AstInterface $node The condition subtree to fold
		 * @return AstInterface The folded subtree
		 * @throws EntityResolutionException
		 */
		private function fold(AstInterface $node): AstInterface {
			if ($node instanceof AstIsFloat) {
				// Fold to true when the column is already float, false when it's
				// integer (an integer is never a float), null when type is unknown.
				$result = $this->foldIsFloat($node);
				if ($result !== null) return $result;
			}
			
			if ($node instanceof AstIsInteger) {
				$result = $this->foldIsInteger($node);
				if ($result !== null) return $result;
			}
			
			if ($node instanceof AstIsNumeric) {
				$result = $this->foldIsNumeric($node);
				if ($result !== null) return $result;
			}
			
			// Recurse into NOT — the type check may be nested, e.g. NOT is_float(x)
			if ($node instanceof AstNot) {
				$node->setExpression($this->fold($node->getExpression()));
				return $node;
			}
			
			// Recurse into AND / OR — either operand may contain a type check
			if ($node instanceof AstBinaryOperator) {
				$node->setLeft($this->fold($node->getLeft()));
				$node->setRight($this->fold($node->getRight()));
				return $node;
			}
			
			// Recurse into comparison expressions — handles is_float(...) = 0 / = 1
			// where the type check is the left or right operand of an = or <> test.
			if ($node instanceof AstExpression) {
				$node->setLeft($this->fold($node->getLeft()));
				$node->setRight($this->fold($node->getRight()));
				return $node;
			}
			
			// Leaf node (identifier, literal, parameter, etc.) — nothing to fold
			return $node;
		}
		
		/**
		 * Folds is_float() to a boolean constant, or null if type is unknown.
		 * float → true, integer → false (integers are never floats).
		 * @param AstIsFloat $node The node to fold
		 * @return AstBool|null Folded constant, or null if type is unknown
		 * @throws EntityResolutionException
		 */
		private function foldIsFloat(AstIsFloat $node): ?AstBool {
			return match ($this->typeInference->inferReturnType($node->getValue())) {
				'float'   => new AstBool(true),
				'integer' => new AstBool(false),
				default   => null,
			};
		}
		
		/**
		 * Folds is_integer() to a boolean constant, or null if type is unknown.
		 * integer → true, float → false (floats are never integers).
		 * @param AstIsInteger $node The node to fold
		 * @return AstBool|null Folded constant, or null if type is unknown
		 * @throws EntityResolutionException
		 */
		private function foldIsInteger(AstIsInteger $node): ?AstBool {
			return match ($this->typeInference->inferReturnType($node->getValue())) {
				'integer' => new AstBool(true),
				'float'   => new AstBool(false),
				default   => null,
			};
		}
		
		/**
		 * Folds is_numeric() to a boolean constant, or null if type is unknown.
		 * Both integer and float are numeric, so either → true.
		 * @param AstIsNumeric $node The node to fold
		 * @return AstBool|null Folded constant, or null if type is unknown
		 * @throws EntityResolutionException
		 */
		private function foldIsNumeric(AstIsNumeric $node): ?AstBool {
			return match ($this->typeInference->inferReturnType($node->getValue())) {
				'integer', 'float' => new AstBool(true),
				default            => null,
			};
		}
	}