<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeConditionWrapper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSearch;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Extracts and filters condition subtrees from an AstRetrieve WHERE clause.
	 *
	 * All methods operate on condition ASTs and return filtered copies — they never
	 * modify the original AST. The three public methods cover the three distinct
	 * filtering needs during query decomposition:
	 *
	 *   - filterDatabaseCompatibleConditions(): strips out anything that references
	 *     non-database ranges (e.g. JSON), leaving only conditions the database can run.
	 *   - isolateFilterConditionsForRange(): extracts scalar filter conditions for a
	 *     specific range (e.g. x.value > 100), excluding join conditions.
	 *   - isolateJoinConditionsForRange(): extracts join conditions that connect a
	 *     specific range to another range (e.g. x.id = y.xId).
	 *
	 * All three delegate tree traversal to filterTree(), which handles structural
	 * node types (AstBinaryOperator, NodeConditionWrapper) via their interfaces
	 * and passes leaf nodes to a caller-supplied predicate. Adding a new filter
	 * only requires writing a predicate — the traversal logic is shared.
	 *
	 * Leaf classification is handled by three private helpers:
	 *   - isDatabaseCompatibleExpression()
	 *   - isFilterCondition()
	 *   - isJoinCondition()
	 */
	class ConditionFilter {
		
		/**
		 * @var ConditionAnalyzer Used to interrogate which ranges a condition references
		 */
		private ConditionAnalyzer $analyzer;
		
		/**
		 * @param ConditionAnalyzer $analyzer
		 */
		public function __construct(ConditionAnalyzer $analyzer) {
			$this->analyzer = $analyzer;
		}
		
		/**
		 * Extracts conditions that can be executed directly by the database engine.
		 *
		 * Filters the condition tree to include only expressions evaluable by the
		 * database (based on the provided database ranges), removing any parts that
		 * require in-memory processing (e.g. JSON operations).
		 * @param AstInterface|null $condition The condition AST to filter
		 * @param array<int, AstRange> $dbRanges Ranges that can be handled by the database
		 * @return AstInterface|null The filtered condition AST, or null if nothing can be handled by the DB
		 */
		public function filterDatabaseCompatibleConditions(?AstInterface $condition, array $dbRanges): ?AstInterface {
			return $this->filterTree($condition, function (AstInterface $node) use ($dbRanges): ?AstInterface {
				// NodeSearch covers AstSearch, AstSearchLike, and AstSearchFullText.
				// Keep only if every identifier it searches references a DB range.
				if ($node instanceof NodeSearch) {
					foreach ($node->getIdentifiers() as $identifier) {
						if (!$this->analyzer->hasReferenceToAnyRange($identifier, $dbRanges)) {
							return null;
						}
					}
					
					return $node;
				}
				
				if ($node instanceof AstExpression) {
					return $this->isDatabaseCompatibleExpression($node, $dbRanges) ? clone $node : null;
				}
				
				// Pure literals with no range references can be pushed to the DB.
				return !$this->analyzer->containsAnyRangeReference($node) ? clone $node : null;
			});
		}
		
		/**
		 * Extracts scalar filter conditions for a specific range, excluding join conditions.
		 *
		 * A filter condition has one side referencing $range and the other side being
		 * a plain literal with no range references (e.g. x.value > 100).
		 * @param AstRange $range The range to extract filter conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The filter conditions for this range, or null if none exist
		 */
		public function isolateFilterConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterTree($whereCondition, function (AstInterface $node) use ($range): ?AstInterface {
				if (!$node instanceof AstExpression) {
					return null;
				}
				
				return $this->isFilterCondition($node, $range) ? clone $node : null;
			});
		}
		
		/**
		 * Extracts join conditions that connect a specific range to any other range.
		 *
		 * A join condition has one side referencing $range and the other side referencing
		 * a different range (e.g. x.id = y.xId). Conditions where both sides reference
		 * the same range, or where one side is a literal, are excluded.
		 * @param AstRange $range The range to extract join conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The join conditions involving this range, or null if none exist
		 */
		public function isolateJoinConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterTree($whereCondition, function (AstInterface $node) use ($range): ?AstInterface {
				if (!$node instanceof AstExpression) {
					return null;
				}
				
				return $this->isJoinCondition($node, $range) ? clone $node : null;
			});
		}
		
		/**
		 * Returns true if the expression can be evaluated entirely by the database.
		 *
		 * An expression is DB-compatible when at least one side references a DB range
		 * and the other side is either also a DB range (join) or a plain literal
		 * (scalar filter). Expressions where one side is a non-DB range (e.g. JSON)
		 * are excluded.
		 * @param AstExpression $expr The comparison expression to test
		 * @param array<int, AstRange> $dbRanges Ranges that can be handled by the database
		 * @return bool
		 */
		private function isDatabaseCompatibleExpression(AstExpression $expr, array $dbRanges): bool {
			$leftDb  = $this->analyzer->hasReferenceToAnyRange($expr->getLeft(),  $dbRanges);
			$rightDb = $this->analyzer->hasReferenceToAnyRange($expr->getRight(), $dbRanges);
			
			// field op field — join between two DB ranges
			if ($leftDb && $rightDb) {
				return true;
			}
			
			// field op literal — scalar filter on a DB range
			if ($leftDb && !$this->analyzer->containsAnyRangeReference($expr->getRight())) {
				return true;
			}
			
			// literal op field — same as above, operands reversed
			if ($rightDb && !$this->analyzer->containsAnyRangeReference($expr->getLeft())) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Returns true if the expression is a scalar filter condition for $range.
		 *
		 * Exactly one side must reference $range; the other side must contain no
		 * range references at all (i.e. it is a literal or parameter). Expressions
		 * where both sides reference a range are join conditions, not filter conditions.
		 * @param AstExpression $expr The comparison expression to test
		 * @param AstRange $range The range being filtered
		 * @return bool
		 */
		private function isFilterCondition(AstExpression $expr, AstRange $range): bool {
			$leftInvolvesRange  = $this->analyzer->doesConditionInvolveRangeCached($expr->getLeft(),  $range);
			$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getRight(), $range);
			
			// range op literal
			if ($leftInvolvesRange && !$this->analyzer->containsAnyRangeReference($expr->getRight())) {
				return true;
			}
			
			// literal op range
			if ($rightInvolvesRange && !$this->analyzer->containsAnyRangeReference($expr->getLeft())) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Returns true if the expression is a join condition involving $range.
		 *
		 * One side must reference $range and the other side must reference a different
		 * range. Expressions where both sides reference the same range, or where one
		 * side is a plain literal, are not join conditions.
		 * @param AstExpression $expr The comparison expression to test
		 * @param AstRange $range The range being joined
		 * @return bool
		 */
		private function isJoinCondition(AstExpression $expr, AstRange $range): bool {
			$leftInvolvesRange  = $this->analyzer->doesConditionInvolveRangeCached($expr->getLeft(),  $range);
			$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getRight(), $range);
			
			// range op other-range
			if ($leftInvolvesRange && $this->analyzer->containsAnyRangeReference($expr->getRight()) && !$rightInvolvesRange) {
				return true;
			}
			
			// other-range op range
			if ($rightInvolvesRange && $this->analyzer->containsAnyRangeReference($expr->getLeft()) && !$leftInvolvesRange) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Recursively filters a condition tree by structural role, delegating leaf
		 * decisions to a caller-supplied predicate.
		 *
		 * Structural dispatch:
		 *   - AstBinaryOperator (AND/OR): recurse into both children; reconstruct the
		 *     node from whichever children survive, collapsing to a single child when
		 *     only one side passes.
		 *   - NodeConditionWrapper (NOT, IS NULL, IS NOT NULL): recurse into the inner
		 *     expression; reconstruct the wrapper with the filtered inner node if it
		 *     survives, otherwise discard the whole wrapper.
		 *   - Everything else is a leaf: pass directly to $predicate and return its result.
		 *
		 * The predicate receives any non-structural node and returns either the node to
		 * keep (cloned if needed) or null to discard it. The predicate is responsible
		 * for cloning — filterTree never clones leaf nodes itself.
		 * @param AstInterface|null $condition Root of the subtree to filter
		 * @param callable(AstInterface): ?AstInterface $predicate Leaf classification test
		 * @return AstInterface|null Filtered subtree, or null if nothing survived
		 */
		private function filterTree(?AstInterface $condition, callable $predicate): ?AstInterface {
			if ($condition === null) {
				return null;
			}
			
			// AND / OR: recurse into both children and reconstruct from survivors.
			if ($condition instanceof AstBinaryOperator) {
				$left  = $this->filterTree($condition->getLeft(),  $predicate);
				$right = $this->filterTree($condition->getRight(), $predicate);
				
				if ($left !== null && $right !== null) {
					$node = clone $condition;
					$node->setLeft($left);
					$node->setRight($right);
					return $node;
				}
				
				// One side was eliminated — collapse to whichever child survived.
				return $left ?? $right;
			}
			
			// NOT / IS NULL / IS NOT NULL: recurse into the inner expression and
			// rebuild the wrapper around it if the inner expression survives.
			if ($condition instanceof NodeConditionWrapper) {
				$inner = $this->filterTree($condition->getExpression(), $predicate);
				
				if ($inner !== null) {
					$node = $condition->deepClone();
					$node->setExpression($inner);
					return $node;
				}
				
				return null;
			}
			
			// Leaf node — delegate entirely to the predicate.
			return $predicate($condition);
		}
	}