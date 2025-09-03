<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	
	final class RangeUsageAnalyzer {
		
		private EntityStore $entityStore;
		
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Analyze an ANY(...) node once and return usage maps keyed by range name.
		 * @param AstAny $any
		 * @param AstRange[] $ranges
		 * @return array{
		 *   usedInExpr: array<string,bool>,
		 *   usedInCond: array<string,bool>,
		 *   hasIsNullInCond: array<string,bool>,
		 *   nonNullableUse: array<string,bool>
		 * }
		 */
		public function analyze(AstAny $any, array $ranges): array {
			$names = array_map(fn($r) => $r->getName(), $ranges);
			$usedInExpr = array_fill_keys($names, false);
			$usedInCond = array_fill_keys($names, false);
			$hasIsNullInCond = array_fill_keys($names, false);
			$nonNullableUse = array_fill_keys($names, false);
			
			// Collect identifiers once
			$exprIds = $this->collectIdentifiers($any->getIdentifier());
			$condIds = $this->collectIdentifiers($any->getConditions());
			
			foreach ($exprIds as $id) {
				$usedInExpr[$id->getRange()->getName()] = true;
			}
			foreach ($condIds as $id) {
				$usedInCond[$id->getRange()->getName()] = true;
				// track non-nullable usage when we see field identifiers
				if ($this->isNonNullableField($id)) {
					$nonNullableUse[$id->getRange()->getName()] = true;
				}
			}
			
			// Single walk to detect IS NULL checks per range in the ANY() condition
			if ($any->getConditions() !== null) {
				$this->walkForIsNull($any->getConditions(), $hasIsNullInCond);
			}
			
			return compact('usedInExpr', 'usedInCond', 'hasIsNullInCond', 'nonNullableUse');
		}
		
		/** @return AstIdentifier[] */
		private function collectIdentifiers(?AstInterface $node): array {
			if ($node === null) {
				return [];
			}
			
			$visitor = new CollectIdentifiers();
			$node->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Check if field is nullable
		 * @param AstIdentifier $id
		 * @return bool
		 */
		private function isNonNullableField(AstIdentifier $id): bool {
			$entityName = $id->getEntityName();
			$columnMap = $this->entityStore->extractEntityColumnDefinitions($entityName);
			$field = $id->getNext()->getName();
			
			if (!isset($columnMap[$field])) {
				// Unknown field: be conservative (treat as nullable -> false)
				return false;
			}
			
			$nullable = (bool)($columnMap[$field]['nullable'] ?? true);
			return !$nullable;
		}
		
		/** sets hasIsNullInCond[rangeName]=true if node contains "range.field IS NULL" */
		private function walkForIsNull(AstInterface $node, array &$hasIsNullInCond): void {
			// If you have a ContainsCheckIsNullForRange visitor, replace this with one pass:
			// but here’s a generic structure relying on your AST:
			if ($node instanceof AstCheckNull) {
				$ids = $this->collectIdentifiers($node->getExpression());
				
				foreach ($ids as $id) {
					$hasIsNullInCond[$id->getRange()->getName()] = true;
				}
			}

			if ($node instanceof AstBinaryOperator) {
				$this->walkForIsNull($node->getLeft(), $hasIsNullInCond);
				$this->walkForIsNull($node->getRight(), $hasIsNullInCond);
			}
		}
	}

