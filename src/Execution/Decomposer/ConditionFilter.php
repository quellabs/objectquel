<?php
	
	namespace Quellabs\ObjectQuel\Execution\Decomposer;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
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
		 * This function filters a condition tree to include only expressions that can be
		 * evaluated by the database (based on the provided database ranges), removing any
		 * parts that would require in-memory processing (like JSON operations).
		 * @param AstInterface|null $condition The condition AST to filter
		 * @param array<int, AstRange> $dbRanges Array of ranges that can be handled by the database
		 * @return AstInterface|null The filtered condition AST, or null if nothing can be handled by DB
		 */
		public function filterDatabaseCompatibleConditions(?AstInterface $condition, array $dbRanges): ?AstInterface {
			// Base case: if no condition provided, return null
			if ($condition === null) {
				return null;
			}
			
			// Handle unary operations (NOT, IS NULL, etc.)
			if ($condition instanceof AstUnaryOperation) {
				// Recursively process the inner expression
				$innerCondition = $this->filterDatabaseCompatibleConditions($condition->getExpression(), $dbRanges);
				
				// If inner expression can be handled by DB, create a new unary operation with it
				if ($innerCondition !== null) {
					return new AstUnaryOperation($innerCondition, $condition->getOperator());
				}
				
				// If inner expression can't be handled by DB, return null
				return null;
			}
			
			// Handle comparison operations (e.g., =, >, <, LIKE, etc.)
			if ($condition instanceof AstExpression) {
				// Check if either side of the expression involves database fields
				$leftInvolvesDb = $this->analyzer->hasReferenceToAnyRange($condition->getLeft(), $dbRanges);
				$rightInvolvesDb = $this->analyzer->hasReferenceToAnyRange($condition->getRight(), $dbRanges);
				
				// Case 1: Keep expressions where both sides involve database ranges
				// (e.g., table1.column = table2.column)
				if ($leftInvolvesDb && $rightInvolvesDb) {
					return clone $condition;
				}
				
				// Case 2: Keep expressions where left side is a DB field and right side is a literal
				// (e.g., table.column = 'value')
				if ($leftInvolvesDb && !$this->analyzer->containsAnyRangeReference($condition->getRight())) {
					return clone $condition;
				}
				
				// Case 3: Keep expressions where right side is a DB field and left side is a literal
				// (e.g., 'value' = table.column)
				if ($rightInvolvesDb && !$this->analyzer->containsAnyRangeReference($condition->getLeft())) {
					return clone $condition;
				}
				
				// If expression involves JSON ranges or other non-DB operations, exclude it
				return null;
			}
			
			// Handle full-text search conditions — they belong to the database
			if ($condition instanceof AstSearch) {
				if ($this->analyzer->hasReferenceToAnyRange($condition, $dbRanges)) {
					return clone $condition;
				}
				
				return null;
			}
			
			// Handle binary operators (AND, OR)
			if ($condition instanceof AstBinaryOperator) {
				// Recursively process both sides of the operator
				$leftCondition = $this->filterDatabaseCompatibleConditions($condition->getLeft(), $dbRanges);
				$rightCondition = $this->filterDatabaseCompatibleConditions($condition->getRight(), $dbRanges);
				
				// Case 1: If both sides have valid database conditions
				// (e.g., (table1.col = 5) AND (table2.col = 'text'))
				if ($leftCondition !== null && $rightCondition !== null) {
					$newNode = clone $condition;
					$newNode->setLeft($leftCondition);
					$newNode->setRight($rightCondition);
					return $newNode;
				}
				
				// Case 2: If left or right side has valid database conditions
				return $leftCondition !== null ? $leftCondition : $rightCondition;
			}
			
			// For literals or other standalone expressions that don't involve any ranges.
			// These can be safely pushed to the database.
			if (!$this->analyzer->containsAnyRangeReference($condition)) {
				return clone $condition;
			}
			
			// Default case: condition not suitable for database execution
			return null;
		}
		
		/**
		 * Extracts just the filtering conditions for a specific range (not join conditions)
		 * @param AstRange $range The range to extract filter conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The filter conditions for this range
		 */
		public function isolateFilterConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterConditionsByCustomCriteria(
				$range,
				$whereCondition,
				function (AstExpression $expr, AstRange $range) {
					$leftInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getLeft(), $range);
					$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getRight(), $range);
					
					// If only one side involves our range and the other doesn't involve any range,
					// it's a filter condition (e.g., x.value > 100)
					return
						($leftInvolvesRange && !$this->analyzer->containsAnyRangeReference($expr->getRight())) ||
						($rightInvolvesRange && !$this->analyzer->containsAnyRangeReference($expr->getLeft()));
				}
			);
		}
		
		/**
		 * Extracts the join conditions involving a specific range with any other range
		 * @param AstRange $range The range to extract join conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The join conditions involving this range
		 */
		public function isolateJoinConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterConditionsByCustomCriteria(
				$range,
				$whereCondition,
				function (AstExpression $expr, AstRange $range) {
					$leftInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getLeft(), $range);
					$rightInvolvesRange = $this->analyzer->doesConditionInvolveRangeCached($expr->getRight(), $range);
					
					// If one side involves our range and the other side involves a different range,
					// then it's a join condition
					return
						($leftInvolvesRange && $this->analyzer->containsAnyRangeReference($expr->getRight()) && !$rightInvolvesRange) ||
						($rightInvolvesRange && $this->analyzer->containsAnyRangeReference($expr->getLeft()) && !$leftInvolvesRange);
				}
			);
		}
		
		/**
		 * Base helper method to extract conditions based on a predicate function
		 * @param AstRange $range The range to extract conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @param callable $predicate Function that determines if a condition should be included
		 * @return AstInterface|null The filtered conditions
		 */
		private function filterConditionsByCustomCriteria(AstRange $range, ?AstInterface $whereCondition, callable $predicate): ?AstInterface {
			// Base case: no condition
			if ($whereCondition === null) {
				return null;
			}
			
			// For comparison operations
			if ($whereCondition instanceof AstExpression) {
				// Use the predicate to determine if we should include this expression
				if ($predicate($whereCondition, $range)) {
					return clone $whereCondition;
				}
				
				return null;
			}
			
			// For binary operators (AND, OR)
			if ($whereCondition instanceof AstBinaryOperator) {
				$leftConditions = $this->filterConditionsByCustomCriteria($range, $whereCondition->getLeft(), $predicate);
				$rightConditions = $this->filterConditionsByCustomCriteria($range, $whereCondition->getRight(), $predicate);
				
				// If both sides have conditions
				if ($leftConditions !== null && $rightConditions !== null) {
					$newNode = clone $whereCondition;
					$newNode->setLeft($leftConditions);
					$newNode->setRight($rightConditions);
					return $newNode;
				}
				
				// If only one side has conditions
				if ($leftConditions !== null) {
					return $leftConditions;
				} elseif ($rightConditions !== null) {
					return $rightConditions;
				}
			}
			
			return null;
		}
	}