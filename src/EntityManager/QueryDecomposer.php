<?php
	
	namespace Services\EntityManager;
	
	use Services\ObjectQuel\Ast\AstBinaryOperator;
	use Services\ObjectQuel\Ast\AstExpression;
	use Services\ObjectQuel\Ast\AstFactor;
	use Services\ObjectQuel\Ast\AstIdentifier;
	use Services\ObjectQuel\Ast\AstRange;
	use Services\ObjectQuel\Ast\AstRetrieve;
	use Services\ObjectQuel\Ast\AstTerm;
	use Services\ObjectQuel\Ast\AstUnaryOperation;
	use Services\ObjectQuel\AstInterface;
	use Services\ObjectQuel\QuelException;
	use Services\Signalize\Ast\AstBool;
	use Services\Signalize\Ast\AstNumber;
	use Services\Signalize\Ast\AstString;
	
	/**
	 * QueryDecomposer is responsible for breaking down complex queries into simpler
	 * execution stages that can be managed by an ExecutionPlan.
	 */
	class QueryDecomposer {
		
		/**
		 * Reference to the EntityManager instance
		 * Used to access entity metadata and other resources needed for query analysis
		 * @var QueryExecutor
		 */
		private QueryExecutor $queryExecutor;
		
		/**
		 * Creates a new QueryDecomposer instance
		 * @param QueryExecutor $queryExecutor
		 */
		public function __construct(QueryExecutor $queryExecutor) {
			$this->queryExecutor = $queryExecutor;
		}
		
		/**
		 * Creates a database-only subset of the original query.
		 *
		 * This method extracts a portion of the query that can be executed entirely by
		 * the database engine, filtering out any parts that would require post-processing
		 * or in-memory evaluation (such as JSON operations).
		 * @param AstRetrieve $query The original query AST
		 * @return AstRetrieve A new query AST with only database-executable components
		 */
		protected function extractDatabaseOnlyQuery(AstRetrieve $query): AstRetrieve {
			// Clone the query to avoid modifying the original
			// This ensures we preserve the complete query for potential in-memory operations later
			$dbQuery = clone $query;
			
			// Get all database ranges (tables/views that exist in the database)
			// These are data sources that SQL can directly access
			$dbRanges = $query->getDatabaseRanges();
			
			// Remove any non-database ranges (e.g., in-memory collections, JSON data)
			// The resulting query will only reference actual database tables/views
			$dbQuery->setRanges($dbRanges);
			
			// Filter the conditions to include only those relevant to database ranges
			// This removes conditions that can't be executed by the database engine
			// and preserves the structure of AND/OR operations where possible
			$dbQuery->setConditions($this->getDatabaseOnlyConditions($query->getConditions(), $dbRanges));
			
			// Return the optimized query that can be fully executed by the database
			return $dbQuery;
		}
		
		/**
		 * Extracts conditions that can be executed directly by the database engine.
		 *
		 * This function filters a condition tree to include only expressions that can be
		 * evaluated by the database (based on the provided database ranges), removing any
		 * parts that would require in-memory processing (like JSON operations).
		 * @param AstInterface|null $condition The condition AST to filter
		 * @param array $dbRanges Array of ranges that can be handled by the database
		 * @return AstInterface|null The filtered condition AST, or null if nothing can be handled by DB
		 */
		protected function getDatabaseOnlyConditions(?AstInterface $condition, array $dbRanges): ?AstInterface {
			// Base case: if no condition provided, return null
			if ($condition === null) {
				return null;
			}
			
			// Handle unary operations (NOT, IS NULL, etc.)
			if ($condition instanceof AstUnaryOperation) {
				// Recursively process the inner expression
				$innerCondition = $this->getDatabaseOnlyConditions($condition->getExpression(), $dbRanges);
				
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
				$leftInvolvesDb = $this->doesConditionInvolveAnyRange($condition->getLeft(), $dbRanges);
				$rightInvolvesDb = $this->doesConditionInvolveAnyRange($condition->getRight(), $dbRanges);
				
				// Case 1: Keep expressions where both sides involve database ranges
				// (e.g., table1.column = table2.column)
				if ($leftInvolvesDb && $rightInvolvesDb) {
					return clone $condition;
				}
				
				// Case 2: Keep expressions where left side is a DB field and right side is a literal
				// (e.g., table.column = 'value')
				if ($leftInvolvesDb && !$this->involvesAnyRange($condition->getRight())) {
					return clone $condition;
				}
				
				// Case 3: Keep expressions where right side is a DB field and left side is a literal
				// (e.g., 'value' = table.column)
				if ($rightInvolvesDb && !$this->involvesAnyRange($condition->getLeft())) {
					return clone $condition;
				}
				
				// If expression involves JSON ranges or other non-DB operations, exclude it
				return null;
			}
			
			// Handle binary operators (AND, OR)
			if ($condition instanceof AstBinaryOperator) {
				// Recursively process both sides of the operator
				$leftCondition = $this->getDatabaseOnlyConditions($condition->getLeft(), $dbRanges);
				$rightCondition = $this->getDatabaseOnlyConditions($condition->getRight(), $dbRanges);
				
				// Case 1: If both sides have valid database conditions
				// (e.g., (table1.col = 5) AND (table2.col = 'text'))
				if ($leftCondition !== null && $rightCondition !== null) {
					$newNode = clone $condition;
					$newNode->setLeft($leftCondition);
					$newNode->setRight($rightCondition);
					return $newNode;
				}
				
				// Case 2: If only left side has valid database conditions
				if ($leftCondition !== null) {
					return $leftCondition;
				}
				
				// Case 3: If only right side has valid database conditions
				if ($rightCondition !== null) {
					return $rightCondition;
				}
				
				// Case 4: If neither side has valid database conditions
				return null;
			}
			
			// For literals or other standalone expressions that don't involve any ranges.
			// These can be safely pushed to the database.
			if (!$this->involvesAnyRange($condition)) {
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
		protected function extractFilterConditions(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			// Do nothing if there's no $whereCondition
			if ($whereCondition === null) {
				return null;
			}
			
			// For comparison operations, check if it's a filter condition
			if ($whereCondition instanceof AstExpression) {
				$leftInvolvesRange = $this->doesConditionInvolveRange($whereCondition->getLeft(), $range);
				$rightInvolvesRange = $this->doesConditionInvolveRange($whereCondition->getRight(), $range);
				
				// If only one side involves our range and the other doesn't involve any range,
				// it's a filter condition (e.g., x.value > 100)
				if ($leftInvolvesRange && !$this->involvesAnyRange($whereCondition->getRight())) {
					return clone $whereCondition;
				}
				
				if ($rightInvolvesRange && !$this->involvesAnyRange($whereCondition->getLeft())) {
					return clone $whereCondition;
				}
				
				// Otherwise, it might be a join condition, so we don't include it here
				return null;
			}
			
			// For binary operators (AND, OR)
			if ($whereCondition instanceof AstBinaryOperator) {
				$leftFilters = $this->extractFilterConditions($range, $whereCondition->getLeft());
				$rightFilters = $this->extractFilterConditions($range, $whereCondition->getRight());
				
				// If both sides have filters
				if ($leftFilters !== null && $rightFilters !== null) {
					$newNode = clone $whereCondition;
					$newNode->setLeft($leftFilters);
					$newNode->setRight($rightFilters);
					return $newNode;
				}
				
				// If only one side has filters
				if ($leftFilters !== null) {
					return $leftFilters;
				} elseif ($rightFilters !== null) {
					return $rightFilters;
				} else {
					return null;
				}
			}
			
			return null;
		}
		
		/**
		 * Extracts join conditions between two specific ranges
		 * @param AstRange $rangeA First range in the join
		 * @param AstRange $rangeB Second range in the join
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The join conditions between these ranges
		 */
		protected function extractJoinConditions(AstRange $rangeA, AstRange $rangeB, ?AstInterface $whereCondition): ?AstInterface {
			if ($whereCondition === null) {
				return null;
			}
			
			// For comparison operations, check if it's a join condition
			if ($whereCondition instanceof AstExpression) {
				$leftInvolvesA = $this->doesConditionInvolveRange($whereCondition->getLeft(), $rangeA);
				$leftInvolvesB = $this->doesConditionInvolveRange($whereCondition->getLeft(), $rangeB);
				$rightInvolvesA = $this->doesConditionInvolveRange($whereCondition->getRight(), $rangeA);
				$rightInvolvesB = $this->doesConditionInvolveRange($whereCondition->getRight(), $rangeB);
				
				// If one side involves rangeA and the other involves rangeB, it's a join condition
				if (($leftInvolvesA && $rightInvolvesB) || ($leftInvolvesB && $rightInvolvesA)) {
					return clone $whereCondition;
				}
				
				// Otherwise, it's not a join condition between these specific ranges
				return null;
			}
			
			// For binary operators (AND, OR)
			if ($whereCondition instanceof AstBinaryOperator) {
				$leftJoins = $this->extractJoinConditions($rangeA, $rangeB, $whereCondition->getLeft());
				$rightJoins = $this->extractJoinConditions($rangeA, $rangeB, $whereCondition->getRight());
				
				// If both sides have join conditions
				if ($leftJoins !== null && $rightJoins !== null) {
					$newNode = clone $whereCondition;
					$newNode->setLeft($leftJoins);
					$newNode->setRight($rightJoins);
					return $newNode;
				}
				
				// If only one side has join conditions
				if ($leftJoins !== null) {
					return $leftJoins;
				} elseif ($rightJoins !== null) {
					return $rightJoins;
				} else {
					return null;
				}
			}
			
			return null;
		}
		
		/**
		 * Determines if an AST node involves any data range (database table or other data source).
		 * This recursive method checks whether any part of the given condition references
		 * a data range, which helps identify expressions that need database or in-memory execution.
		 * @param AstInterface $condition The AST node to check
		 * @return bool True if the condition involves any data range, false otherwise
		 */
		private function involvesAnyRange(AstInterface $condition): bool {
			// For identifiers (column names), check if they have an associated range
			if ($condition instanceof AstIdentifier) {
				// An identifier with a range represents a field from a table or other data source
				return $condition->getRange() !== null;
			}
			
			// For unary operations (NOT, IS NULL, etc.), check the inner expression
			if ($condition instanceof AstUnaryOperation) {
				// Recursively check if the inner expression involves any range
				return $this->involvesAnyRange($condition->getExpression());
			}
			
			// For binary nodes with left and right children, check both sides
			if (
				$condition instanceof AstExpression ||   // Comparison expressions (=, <, >, etc.)
				$condition instanceof AstBinaryOperator || // Logical operators (AND, OR)
				$condition instanceof AstTerm ||         // Addition, subtraction
				$condition instanceof AstFactor          // Multiplication, division
			) {
				// Return true if either the left or right side involves any range
				return
					$this->involvesAnyRange($condition->getLeft()) ||
					$this->involvesAnyRange($condition->getRight());
			}
			
			// Literals (numbers, strings) and other node types don't involve ranges
			return false;
		}
		
		/**
		 * Transforms a WHERE condition to include only parts involving specific ranges.
		 * @param array $ranges Array of AstRange objects to keep in the condition
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The transformed condition, or null if no parts involve our ranges
		 */
		protected function getWherePartOfRange(array $ranges, ?AstInterface $whereCondition): ?AstInterface {
			if ($whereCondition === null) {
				return null;
			}

			// Check if the condition involves any of our ranges
			if (!$this->doesConditionInvolveAnyRange($whereCondition, $ranges)) {
				return null;
			}

			// If we're at a leaf node (identifier or literal)
			if (
				$whereCondition instanceof AstIdentifier ||
				$whereCondition instanceof AstString ||
				$whereCondition instanceof AstNumber ||
				$whereCondition instanceof AstBool
			) {
				return $whereCondition; // Keep the node as is
			}

			// For binary operations (expressions, operators, terms, factors)
			if (
				$whereCondition instanceof AstExpression ||
				$whereCondition instanceof AstBinaryOperator ||
				$whereCondition instanceof AstTerm ||
				$whereCondition instanceof AstFactor
			) {
				// For comparison operations, if either side involves our ranges, keep the entire expression
				if ($whereCondition instanceof AstExpression) {
					if ($this->doesConditionInvolveAnyRange($whereCondition->getLeft(), $ranges) ||
						$this->doesConditionInvolveAnyRange($whereCondition->getRight(), $ranges)) {
						return clone $whereCondition; // Keep the entire comparison expression
					}
					
					return null;
				}
				
				// Recursively process the left and right children
				$leftTransformed = $this->getWherePartOfRange($ranges, $whereCondition->getLeft());
				$rightTransformed = $this->getWherePartOfRange($ranges, $whereCondition->getRight());
				
				// If both sides have parts involving our ranges
				if ($leftTransformed !== null && $rightTransformed !== null) {
					// Create a clone to avoid modifying the original
					$newNode = clone $whereCondition;
					$newNode->setLeft($leftTransformed);
					$newNode->setRight($rightTransformed);
					return $newNode;
				} // If only left side involves our ranges
				elseif ($leftTransformed !== null) {
					return $leftTransformed;
				} // If only right side involves our ranges
				elseif ($rightTransformed !== null) {
					return $rightTransformed;
				} // If neither side involves our ranges (shouldn't happen due to initial check)
				else {
					return null;
				}
			}
			
			// For unary operations (NOT, etc.)
			if ($whereCondition instanceof AstUnaryOperation) {
				$transformedExpr = $this->getWherePartOfRange($ranges, $whereCondition->getExpression());
				
				if ($transformedExpr !== null) {
					return new AstUnaryOperation($transformedExpr, $whereCondition->getOperator());
				}
				
				return null;
			}
			
			// Return null for the rest
			return null;
		}
		
		/**
		 * Checks if a condition node involves a specific range.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		protected function doesConditionInvolveRange(AstInterface $condition, AstRange $range): bool {
			// For property access, check if the base entity matches our range
			if ($condition instanceof AstIdentifier) {
				return $condition->getRange()->getName() === $range->getName();
			}
			
			// For unary operations (NOT, etc.)
			if ($condition instanceof AstUnaryOperation) {
				return $this->doesConditionInvolveRange($condition->getExpression(), $range);
			}
			
			// For comparison operations, check each side
			if (
				$condition instanceof AstExpression ||
				$condition instanceof AstBinaryOperator ||
				$condition instanceof AstTerm ||
				$condition instanceof AstFactor
			) {
				$leftInvolves = $this->doesConditionInvolveRange($condition->getLeft(), $range);
				$rightInvolves = $this->doesConditionInvolveRange($condition->getRight(), $range);
				return $leftInvolves || $rightInvolves;
			}
			
			return false;
		}
		
		/**
		 * Checks if a condition involves any of the specified ranges
		 * @param AstInterface $condition The condition to check
		 * @param array $ranges Array of AstRange objects
		 * @return bool True if the condition involves any of the ranges
		 */
		protected function doesConditionInvolveAnyRange(AstInterface $condition, array $ranges): bool {
			foreach ($ranges as $range) {
				if ($this->doesConditionInvolveRange($condition, $range)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Decomposes a query into separate execution stages for different data sources.
		 * This function takes a mixed-source query and creates an execution plan with
		 * appropriate stages for database and JSON sources.
		 * @param AstRetrieve $query The ObjectQuel query to decompose
		 * @param array $staticParams Optional static parameters for the query
		 * @return ExecutionPlan|null The execution plan containing all stages, or null if decomposition failed
		 */
		public function decompose(AstRetrieve $query, array $staticParams = []): ?ExecutionPlan {
			// Create a new execution plan to hold all the query stages
			$plan = new ExecutionPlan();
			
			// Get all database ranges from the AST
			// These will be processed together in a single database query
			$databaseRanges = $query->getDatabaseRanges();
			
			// If there are database ranges, create a stage for the database query
			if (!empty($databaseRanges)) {
				// Create a shallow clone of the original AST
				$clonedAst = clone $query;
				
				// Extract only the WHERE conditions that involve database ranges
				// This creates a modified WHERE clause specific to database sources
				$clonedAst->setConditions($this->getWherePartOfRange($databaseRanges, $query->getConditions()));
				
				// Create a new execution stage with a unique ID for this database query
				$plan->createStage(uniqid(), $clonedAst, $staticParams);
			}
			
			// Process each non-database range (like JSON sources) individually
			// Each range gets its own execution stage
			foreach($query->getOtherRanges() as $otherRange) {
				// Create a shallow clone of the original AST for this range
				$clonedAst = clone $query;
				
				// Extract only the WHERE conditions that involve this specific range
				// This creates a modified WHERE clause specific to this data source
				$clonedAst->setConditions($this->getWherePartOfRange([$otherRange], $query->getConditions()));
				
				// Create a new execution stage with a unique ID for this range
				$plan->createStage(uniqid(), $clonedAst, $staticParams, $otherRange);
			}
			
			// Return the complete execution plan with all stages
			return $plan;
		}
	}