<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeSingleExpression;
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
	 * node types (AstBinaryOperator, NodeSingleExpression) via their interfaces,
	 * and passes leaf nodes to a caller-supplied predicate. This means adding a
	 * new filter only requires writing a predicate — the traversal logic is shared.
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
		 * @param array<int, AstRange> $dbRanges Array of ranges that can be handled by the database
		 * @return AstInterface|null The filtered condition AST, or null if nothing can be handled by DB
		 */
		public function filterDatabaseCompatibleConditions(?AstInterface $condition, array $dbRanges): ?AstInterface {
			return $this->filterTree($condition, function (AstInterface $node) use ($dbRanges): ?AstInterface {
				// NodeSearch covers AstSearch, AstSearchLike, and AstSearchFullText.
				// Keep the node only if every identifier it searches references a DB range.
				if ($node instanceof NodeSearch) {
					foreach ($node->getIdentifiers() as $identifier) {
						if (!$this->analyzer->hasReferenceToAnyRange($identifier, $dbRanges)) {
							return null;
						}
					}
					
					return $node;
				}
				
				// Comparison expressions: keep when at least one side is a DB field
				// and the other side is either also a DB field or a plain literal.
				if ($node instanceof AstExpression) {
					$leftDb = $this->analyzer->hasReferenceToAnyRange($node->getLeft(), $dbRanges);
					$rightDb = $this->analyzer->hasReferenceToAnyRange($node->getRight(), $dbRanges);
					
					// field op field (join condition between two DB ranges)
					if ($leftDb && $rightDb) {
						return clone $node;
					}
					
					// field op literal
					if ($leftDb && !$this->analyzer->containsAnyRangeReference($node->getRight())) {
						return clone $node;
					}
					
					// literal op field
					if ($rightDb && !$this->analyzer->containsAnyRangeReference($node->getLeft())) {
						return clone $node;
					}
					
					return null;
				}
				
				// Pure literals (no range references at all) can be pushed to the DB.
				if (!$this->analyzer->containsAnyRangeReference($node)) {
					return clone $node;
				}
				
				return null;
			});
		}
		
		/**
		 * Extracts just the filtering conditions for a specific range (not join conditions).
		 * A filter condition has one side referencing the given range and the other side
		 * being a plain literal (e.g. x.value > 100).
		 * @param AstRange $range The range to extract filter conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The filter conditions for this range
		 */
		public function isolateFilterConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterTree($whereCondition, function (AstInterface $node) use ($range): ?AstInterface {
				if (!$node instanceof AstExpression) {
					return null;
				}
				
				$leftInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($node->getLeft(), $range);
				$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($node->getRight(), $range);
				
				$keep =
					($leftInvolvesRange && !$this->analyzer->containsAnyRangeReference($node->getRight())) ||
					($rightInvolvesRange && !$this->analyzer->containsAnyRangeReference($node->getLeft()));
				
				return $keep ? clone $node : null;
			});
		}
		
		/**
		 * Extracts the join conditions involving a specific range with any other range.
		 * A join condition has one side referencing the given range and the other side
		 * referencing a different range (e.g. x.id = y.xId).
		 * @param AstRange $range The range to extract join conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The join conditions involving this range
		 */
		public function isolateJoinConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterTree($whereCondition, function (AstInterface $node) use ($range): ?AstInterface {
				if (!$node instanceof AstExpression) {
					return null;
				}
				
				$leftInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($node->getLeft(), $range);
				$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($node->getRight(), $range);
				
				$keep =
					($leftInvolvesRange && $this->analyzer->containsAnyRangeReference($node->getRight()) && !$rightInvolvesRange) ||
					($rightInvolvesRange && $this->analyzer->containsAnyRangeReference($node->getLeft()) && !$leftInvolvesRange);
				
				return $keep ? clone $node : null;
			});
		}
		
		/**
		 * Recursively filters a condition tree by structural role, delegating leaf
		 * decisions to a caller-supplied predicate.
		 *
		 * Structural dispatch:
		 *   - AstBinaryOperator (AND/OR): recurse into both children; reconstruct the
		 *     node only if at least one child survives, collapsing to a single child
		 *     when only one side passes.
		 *   - NodeSingleExpression (NOT, IS NULL, etc.): recurse into the inner
		 *     expression; reconstruct the wrapper with the filtered inner node if it
		 *     survives, otherwise discard.
		 *   - Everything else is a leaf: pass directly to $predicate and return its result.
		 *
		 * The predicate receives any non-structural node and returns either the node to
		 * keep (cloned if needed) or null to discard it. The predicate is responsible
		 * for cloning — filterTree itself never clones leaf nodes.
		 *
		 * @param AstInterface|null $condition Root of the subtree to filter
		 * @param callable(AstInterface): ?AstInterface $predicate Leaf test
		 * @return AstInterface|null Filtered subtree, or null if nothing survived
		 */
		private function filterTree(?AstInterface $condition, callable $predicate): ?AstInterface {
			if ($condition === null) {
				return null;
			}
			
			// AND / OR: logical combinator — recurse into both children and
			// reconstruct only from the parts that survive.
			if ($condition instanceof AstBinaryOperator) {
				$left = $this->filterTree($condition->getLeft(), $predicate);
				$right = $this->filterTree($condition->getRight(), $predicate);
				
				if ($left !== null && $right !== null) {
					$node = clone $condition;
					$node->setLeft($left);
					$node->setRight($right);
					return $node;
				}
				
				return $left ?? $right;
			}
			
			// NOT / IS NULL / IS NOT NULL / etc.: unary wrapper — recurse into the
			// inner expression and rebuild the wrapper around the filtered result.
			if ($condition instanceof NodeSingleExpression) {
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