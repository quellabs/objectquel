<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	
	/**
	 * Optimizes JOIN types based on WHERE clause analysis.
	 * Converts LEFT JOINs to INNER JOINs when safe, and vice versa.
	 */
	class AnyOptimizer {
		
		/**
		 * Entity metadata store for field nullability checks
		 */
		private EntityStore $entityStore;
		private AstNodeReplacer $nodeReplacer;
		
		/**
		 * Initialize optimizer with entity metadata access
		 *
		 * @param EntityManager $entityManager Manager providing entity metadata
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->nodeReplacer = new AstNodeReplacer();
		}
		
		/**
		 * Main optimization entry point - analyzes all ranges in the AST
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			foreach($this->getAllAnyNodes($ast) as $node) {
				switch($ast->getLocationOfChild($node)) {
					case 'select' :
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_CASE_WHEN);
						break;

					case 'conditions' :
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_EXISTS);
						break;
					
					case 'order_by' :
						break;
				}
			}
		}
		
		private function optimizeAnyNode(AstRetrieve $ast, AstAny $node, string $subQueryType): void {
			$clonedRanges = $this->cloneRanges($ast);
			$filteredRanges = $this->filterRanges($clonedRanges, $node);
			
			if (
				count($filteredRanges) == 1 &&
				$filteredRanges[0]->getJoinProperty() !== null &&
				$node->getConditions() === null
			) {
				$conditions = $filteredRanges[0]->getJoinProperty();
				$filteredRanges[0]->setJoinProperty(null);
			} else {
				$conditions = $node->getConditions();
			}

			$subQuery = new AstSubquery(
				$subQueryType,
				null,
				$filteredRanges,
				$conditions
			);
			
			$this->nodeReplacer->replaceChild($node->getParent(), $node, $subQuery);
		}

		/**
		 * Filter out unused joins
		 * @param array $ranges
		 * @param AstAny $node
		 * @return array
		 */
		private function filterRanges(array $ranges, AstAny $node): array {
			$result = [];
			
			foreach ($ranges as $range) {
				// Check if range is referenced in ANY(expr) or conditions
				$usedInExpr = $this->containsRange($node->getIdentifier(), $range);
				$usedInCond = $this->containsRange($node->getConditions(), $range);
				
				// If not used at all drop it
				if (!$usedInExpr && !$usedInCond) {
					continue;
				}
				
				// Add range to list
				$result[] = $range;
			}
			
			return $result;
		}
		
		/**
		 * Fetch list of all used identifiers
		 * @param AstInterface|null $ast
		 * @return array
		 */
		private function getIdentifiers(?AstInterface $ast): array {
			if ($ast === null) {
				return [];
			}
			
			$visitor = new CollectIdentifiers();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Returns true if any of the identifiers use the range
		 * @param AstInterface|null $ast
		 * @param AstRange $range
		 * @return bool
		 */
		private function containsRange(?AstInterface $ast, AstRange $range): bool {
			$identifiers = $this->getIdentifiers($ast);
			
			foreach ($identifiers as $identifier) {
				if ($identifier->getRange()->getName() === $range->getName()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Clones ranges
		 * @param AstRetrieve $ast
		 * @return array
		 */
		private function cloneRanges(AstRetrieve $ast): array {
			$result = [];
			
			foreach($ast->getRanges() as $range) {
				$result[] = $range->deepClone();
			}
			
			return $result;
		}
		
		/**
		 * Fetch a list of ANY nodes
		 * @param AstRetrieve $ast
		 * @return array
		 */
		private function getAllAnyNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes([AstAny::class]);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
	}