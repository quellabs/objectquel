<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers\FoldingRules;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeFunction;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Planner\Optimizers\FoldingRuleInterface;
	
	/**
	 * Folding rule for is_float(), is_integer(), and is_numeric() calls.
	 *
	 * Resolves these nodes to a boolean constant when the argument type is
	 * statically known to be numeric. Returns null otherwise, leaving the node
	 * for runtime REGEXP evaluation.
	 */
	class TypeCheckFoldingRule implements FoldingRuleInterface {
		
		/** @var ResolveType Type inference helper backed by entity metadata */
		private ResolveType $typeInference;
		
		/**
		 * Maps each supported type-check node class to the set of PHP types that
		 * make it evaluate to true. Any other numeric type evaluates to false.
		 * Classes not present in this map are ignored by this rule.
		 * @var array<class-string, string[]>
		 */
		private const TYPE_MAP = [
			AstIsFloat::class   => ['float'],
			AstIsInteger::class => ['int', 'integer'],
			AstIsNumeric::class => ['int', 'integer', 'float'],
		];

		/** Only numeric types fold; a string or bool value may still hold a numeric string, so it's checked at runtime */
		private const FOLDABLE_TYPES = ['int', 'integer', 'float'];
		
		/**
		 * @param EntityManager $entityManager Provides entity metadata for type inference
		 */
		public function __construct(EntityManager $entityManager) {
			$this->typeInference = new ResolveType($entityManager->getEntityStore());
		}
		
		/**
		 * Folds a supported type-check node to a boolean constant when the argument
		 * type is statically known. Returns null for unrecognised node types and for
		 * nodes whose argument type cannot be determined.
		 * @param AstInterface $node The node to examine
		 * @return AstBool|null The folded constant, or null if unresolvable
		 * @throws EntityResolutionException
		 */
		public function fold(AstInterface $node): ?AstBool {
			// NodeFunction is the shared interface that declares getValue() on all
			// type-check nodes; anything else is not our concern.
			if (!$node instanceof NodeFunction) {
				return null;
			}
			
			// Look up the true-types for this specific class; skip unknown subclasses
			$trueTypes = self::TYPE_MAP[$node::class] ?? null;
			
			if ($trueTypes === null) {
				return null;
			}
			
			return $this->foldByType($node->getValue(), $trueTypes);
		}
		
		/**
		 * Infers the argument type and maps it to a boolean constant.
		 * Returns null when the type is unknown or not numeric.
		 * @param AstInterface $argument The function's argument node
		 * @param string[] $trueTypes PHP types for which the type-check evaluates to true
		 * @return AstBool|null Folded constant, or null if type is unknown
		 * @throws EntityResolutionException
		 */
		private function foldByType(AstInterface $argument, array $trueTypes): ?AstBool {
			$type = $this->typeInference->inferReturnType($argument);
			
			// Type unknown or not numeric — cannot fold, leave for runtime REGEXP
			if (!in_array($type, self::FOLDABLE_TYPES, true)) {
				return null;
			}
			
			return new AstBool(in_array($type, $trueTypes, true));
		}
	}