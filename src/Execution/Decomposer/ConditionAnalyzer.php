<?php
	
	namespace Quellabs\ObjectQuel\Execution\Decomposer;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfnull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Provides pure AST interrogation methods that answer questions about which
	 * ranges a condition node references. All methods are free of side effects
	 * except for the result cache, which exists purely for performance.
	 *
	 * This class is a dependency of ConditionFilter and StageFactory. It carries
	 * the cache so that a single cache lifetime spans an entire plan-build pass —
	 * clearCache() should be called at the start of each buildExecutionPlan() call.
	 */
	class ConditionAnalyzer {
		
		/**
		 * Cache of results for expensive does-condition-involve-range checks.
		 * Keyed by spl_object_hash pairs to avoid redundant AST traversals.
		 * @var array
		 */
		private array $cache = [];
		
		/**
		 * Clears the internal cache.
		 * Should be called at the start of each buildExecutionPlan() call to
		 * prevent stale results and memory leaks across decomposition passes.
		 */
		public function clearCache(): void {
			$this->cache = [];
		}
		
		/**
		 * Determines if an AST node involves any data range (database table or other data source).
		 * This recursive method checks whether any part of the given condition references
		 * a data range, which helps identify expressions that need database or in-memory execution.
		 * @param AstInterface $condition The AST node to check
		 * @return bool True if the condition involves any data range, false otherwise
		 */
		public function containsAnyRangeReference(AstInterface $condition): bool {
			// For identifiers (column names), check if they have an associated range
			if ($condition instanceof AstIdentifier) {
				// An identifier with a range represents a field from a table or other data source
				return $condition->getRange() !== null;
			}
			
			// For unary operations (NOT, IS NULL, etc.), check the inner expression
			if ($condition instanceof AstUnaryOperation) {
				// Recursively check if the inner expression involves any range
				return $this->containsAnyRangeReference($condition->getExpression());
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
					$this->containsAnyRangeReference($condition->getLeft()) ||
					$this->containsAnyRangeReference($condition->getRight());
			}
			
			// Full-text search nodes contain identifiers that may reference ranges
			if ($condition instanceof AstSearch || $condition instanceof AstSearchScore) {
				foreach ($condition->getIdentifiers() as $identifier) {
					if ($this->containsAnyRangeReference($identifier)) {
						return true;
					}
				}
				
				return false;
			}
			
			// Literals (numbers, strings) and other node types don't involve ranges
			return false;
		}
		
		/**
		 * Checks if a condition node involves a specific range.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		public function hasReferenceToRange(AstInterface $condition, AstRange $range): bool {
			// For property access, check if the base entity matches our range
			if ($condition instanceof AstIdentifier) {
				return $condition->getRange()->getName() === $range->getName();
			}
			
			// For aliases and AstUnaryOperations, check the matching identifier
			if (
				$condition instanceof AstAlias ||
				$condition instanceof AstUnaryOperation ||
				$condition instanceof AstIfnull
			) {
				return $this->hasReferenceToRange($condition->getExpression(), $range);
			}
			
			// For aggregates, check the matching identifier
			if (
				$condition instanceof AstCount ||
				$condition instanceof AstCountU ||
				$condition instanceof AstAvg ||
				$condition instanceof AstAvgU ||
				$condition instanceof AstMax ||
				$condition instanceof AstMin ||
				$condition instanceof AstSum ||
				$condition instanceof AstSumU ||
				$condition instanceof AstAny
			) {
				return $this->hasReferenceToRange($condition->getIdentifier(), $range);
			}
			
			// Full-text search nodes — check if any identifier belongs to this range
			if ($condition instanceof AstSearch || $condition instanceof AstSearchScore) {
				foreach ($condition->getIdentifiers() as $identifier) {
					if ($this->hasReferenceToRange($identifier, $range)) {
						return true;
					}
				}
				
				return false;
			}
			
			// For comparison operations, check each side
			if (
				$condition instanceof AstExpression ||
				$condition instanceof AstBinaryOperator ||
				$condition instanceof AstTerm ||
				$condition instanceof AstFactor
			) {
				$leftInvolves = $this->hasReferenceToRange($condition->getLeft(), $range);
				$rightInvolves = $this->hasReferenceToRange($condition->getRight(), $range);
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
		public function hasReferenceToAnyRange(AstInterface $condition, array $ranges): bool {
			foreach ($ranges as $range) {
				if ($this->doesConditionInvolveRangeCached($condition, $range)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Cached version of hasReferenceToRange to avoid recalculating
		 * for the same condition and range pairs.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		public function doesConditionInvolveRangeCached(AstInterface $condition, AstRange $range): bool {
			// Generate a cache key based on object identities
			$cacheKey = 'involve_' . spl_object_hash($condition) . '_' . spl_object_hash($range);
			
			// Return cached result if available
			if (isset($this->cache[$cacheKey])) {
				return $this->cache[$cacheKey];
			}
			
			// Calculate and cache the result
			$result = $this->hasReferenceToRange($condition, $range);
			$this->cache[$cacheKey] = $result;
			return $result;
		}
	}