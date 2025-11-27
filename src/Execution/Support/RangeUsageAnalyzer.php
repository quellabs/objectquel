<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\IdentifierCollector;
	
	/**
	 * RangeUsageAnalyzer
	 *
	 * Analyzes a single {@see AstAny} node to understand how each range inside the
	 * ANY(...) construct is used across the expression and its predicate (conditions).
	 *
	 * The analysis answers these questions per range:
	 *  - Is the range referenced in the ANY expression (the "projection")?
	 *  - Is the range referenced in the ANY condition (the "filter")?
	 *  - Does the condition contain an explicit `IS NULL` check over any identifier that
	 *    belongs to that range?
	 *  - Is the range used in a way that implies the referenced field is *non-nullable*
	 *    (based on entity metadata in {@see EntityStore})?
	 *
	 * This class performs the work in (at most) two AST traversals:
	 *  1) Collect identifiers from the ANY expression and the ANY condition (if present).
	 *  2) Walk the condition subtree once more to detect explicit `IS NULL` checks.
	 *
	 * Notes / Assumptions:
	 *  - "Non-nullable use" here means: if a condition references a field that is
	 *    declared as non-nullable in the entity metadata, we record that the range
	 *    has at least one non-nullable field usage in the condition.
	 *  - If a field name is unknown in the metadata, we conservatively treat it as
	 *    nullable (i.e., we do NOT mark it as non-nullable use).
	 *
	 * @phpstan-type RangeName string
	 * @phpstan-type RangeUsageMap array{
	 *   usedInExpr: array<RangeName, bool>,
	 *   usedInCond: array<RangeName, bool>,
	 *   hasIsNullInCond: array<RangeName, bool>,
	 *   nonNullableUse: array<RangeName, bool>
	 * }
	 */
	final class RangeUsageAnalyzer {
		
		/**
		 * Metadata source used to resolve entity → column definitions (including nullability).
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * RangeUsageAnalyzer constructor
		 * @param EntityStore $entityStore Metadata provider for entity column definitions.
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Analyze an {@see AstAny} node once and return usage maps keyed by range name.
		 *
		 * The result is a shape containing four boolean maps, each indexed by the
		 * textual name of the range (i.e., {@see AstRange::getName()}):
		 *
		 * - usedInExpr:     true if the range is referenced in the ANY expression part.
		 * - usedInCond:     true if the range is referenced anywhere in the ANY condition.
		 * - hasIsNullInCond:true if the condition contains an explicit `IS NULL` check
		 *                    over any identifier that belongs to the range.
		 * - nonNullableUse: true if the condition references at least one field for this
		 *                    range that is declared non-nullable in metadata.
		 *
		 * Performance: identifier collection happens once for the expression and once
		 * for the condition; the IS NULL detection is a single additional walk over
		 * the condition subtree (if present).
		 *
		 * @param AstAny $any The ANY(...) AST node to analyze.
		 * @param AstRange[] $ranges Ordered list of ranges declared for this ANY.
		 * @return QueryAnalysisResult A shape of boolean maps, one entry per provided range name.
		 */
		public function analyze(AstAny $any, array $ranges): QueryAnalysisResult {
			// Analyze the usage
			$usage = $this->performUsageAnalysis($any->getIdentifier(), $any->getConditions(), $ranges);
			
			// Transform the usage to a QueryAnalysisResult object
			return $this->createAnalysisFromUsage($usage);
		}
		
		/**
		 * Core analysis logic
		 * @param AstInterface|null $expr The expression to analyze for identifier usage
		 * @param AstInterface|null $cond The condition to analyze for identifier usage and IS NULL checks
		 * @param AstRange[] $ranges Ordered list of ranges to analyze
		 * @return array{
		 *   usedInExpr: array<string,bool>,
		 *   usedInCond: array<string,bool>,
		 *   hasIsNullInCond: array<string,bool>,
		 *   nonNullableUse: array<string,bool>
		 * }
		 */
		private function performUsageAnalysis(?AstInterface $expr, ?AstInterface $cond, array $ranges): array {
			// Build an ordered list of range names so that we can pre-seed all maps
			// with false and only flip to true when we detect usage.
			$names = array_map(
			/** @return string */
				static fn(AstRange $r) => $r->getName(),
				$ranges
			);
			
			// Initialize result maps: one slot per declared range name.
			$usedInExpr = array_fill_keys($names, false);
			$usedInCond = array_fill_keys($names, false);
			$hasIsNullInCond = array_fill_keys($names, false);
			$nonNullableUse = array_fill_keys($names, false);
			
			// Collect identifiers from the expression and from the condition.
			// This avoids re-walking the same subtrees multiple times.
			$exprIds = $this->collectIdentifiers($expr);
			$condIds = $this->collectIdentifiers($cond);
			
			// Mark ranges seen in the expression (projection).
			foreach ($exprIds as $id) {
				$rangeName = $id->getRange()->getName();
				$usedInExpr[$rangeName] = true;
			}
			
			// Mark ranges seen in the condition and track non-nullable usage for fields.
			foreach ($condIds as $id) {
				$rangeName = $id->getRange()->getName();
				$usedInCond[$rangeName] = true;
				
				// If the identifier denotes an entity.field and that field is non-nullable
				// per metadata, remember that this range exhibits "nonNullableUse".
				if ($this->isNonNullableField($id)) {
					$nonNullableUse[$rangeName] = true;
				}
			}
			
			// Single walk dedicated to finding explicit "IS NULL" checks per range
			// within the condition tree (if there is any condition at all).
			if ($cond !== null) {
				$this->walkForIsNull($cond, $hasIsNullInCond);
			}
			
			// Return arrays
			return compact('usedInExpr', 'usedInCond', 'hasIsNullInCond', 'nonNullableUse');
		}
		
		/**
		 * Convert usage analysis arrays into a structured analysis object.
		 *
		 * The RangeUsageAnalyzer returns raw arrays mapping table names to boolean flags.
		 * This method converts that into a more structured QueryAnalysisResult object
		 * that contains TableUsageInfo objects for easier manipulation.
		 *
		 * The four usage flags are:
		 * - usedInExpr: table is referenced in the ANY expression itself
		 * - usedInCond: table is referenced in WHERE conditions
		 * - hasIsNullInCond: table has IS NULL checks (affects LEFT JOIN safety)
		 * - nonNullableUse: table is used in a way that requires non-null values
		 *
		 * @param array $usage Raw usage analysis from performUsageAnalysis
		 * @return QueryAnalysisResult Structured analysis object
		 */
		private function createAnalysisFromUsage(array $usage): QueryAnalysisResult {
			$tableUsageMap = [];
			
			// Collect all table names mentioned in any usage category
			// This ensures we don't miss tables that appear in only one category
			$allTableNames = array_unique(array_merge(
				array_keys($usage['usedInExpr'] ?? []),
				array_keys($usage['usedInCond'] ?? []),
				array_keys($usage['hasIsNullInCond'] ?? []),
				array_keys($usage['nonNullableUse'] ?? [])
			));
			
			// Create structured TableUsageInfo objects for each table
			foreach ($allTableNames as $tableName) {
				$tableUsageMap[$tableName] = new TableUsageInfo(
					!empty($usage['usedInExpr'][$tableName]),     // Convert to boolean
					!empty($usage['usedInCond'][$tableName]),     // Handle missing keys safely
					!empty($usage['hasIsNullInCond'][$tableName]),
					!empty($usage['nonNullableUse'][$tableName])
				);
			}
			
			return new QueryAnalysisResult($tableUsageMap);
		}
		
		/**
		 * Collect all {@see AstIdentifier} nodes reachable from the given AST node.
		 * @param AstInterface|null $node Root of the subtree to traverse (or null).
		 * @return AstIdentifier[] In-order list of encountered identifiers.
		 */
		private function collectIdentifiers(?AstInterface $node): array {
			if ($node === null) {
				return [];
			}
			
			$visitor = new IdentifierCollector();
			$node->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Determine whether the provided identifier refers to a known, non-nullable field.
		 * @param AstIdentifier $id Identifier pointing to (or starting from) an entity member.
		 * @return bool True if the field exists in metadata and is marked non-nullable; false otherwise.
		 */
		private function isNonNullableField(AstIdentifier $id): bool {
			// Resolve the entity name (left side of "entity.field").
			$entityName = $id->getEntityName();
			
			// Pull the column definition map from metadata, e.g.:
			//   ['id' => ['nullable' => false, ...], 'name' => ['nullable' => true, ...], ...]
			$columnMap = $this->entityStore->extractEntityColumnDefinitions($entityName);
			
			// Retrieve the immediate member name following the identifier (the "field").
			// This assumes the AST organizes chained identifiers as a linked structure.
			$field = $id->getNext()->getName();
			
			// If the field is unknown, assume nullable (return false for "non-nullable").
			if (!isset($columnMap[$field])) {
				return false;
			}
			
			// Default nullable=true if the key is absent; invert to signal non-nullable use.
			$nullable = (bool)($columnMap[$field]['nullable'] ?? true);
			
			// Return value
			return !$nullable;
		}
		
		/**
		 * Detect explicit `IS NULL` checks inside the given subtree and mark the range map.
		 * @param AstInterface $node Root of the subtree to analyze.
		 * @param array<string,bool> $hasIsNullInCond
		 * @return void
		 */
		private function walkForIsNull(AstInterface $node, array &$hasIsNullInCond): void {
			// If you have a specialized visitor (e.g., ContainsCheckIsNullForRange),
			// it could replace this manual traversal with a single pass visitor.
			if ($node instanceof AstCheckNull) {
				// Collect identifiers inside the "X IS NULL" node's expression.
				$ids = $this->collectIdentifiers($node->getExpression());
				
				foreach ($ids as $id) {
					$rangeName = $id->getRange()->getName();
					$hasIsNullInCond[$rangeName] = true;
				}
			}
			
			// Recurse into binary operators (e.g., AND/OR trees) to find nested checks.
			if ($node instanceof AstBinaryOperator) {
				$this->walkForIsNull($node->getLeft(), $hasIsNullInCond);
				$this->walkForIsNull($node->getRight(), $hasIsNullInCond);
			}
		}
	}