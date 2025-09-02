<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Detects and eliminates redundant self-joins where ranges are functionally equivalent.
	 *
	 * OPTIMIZATION STRATEGY:
	 * Identifies cases where the same entity is joined to itself with an identity condition
	 * (e.g., Customer c JOIN Customer d ON c.id = d.id) and eliminates the redundant range.
	 *
	 * CRITICAL ASSUMPTIONS:
	 * - Identity joins (same field equality) make ranges functionally equivalent
	 * - No side effects from range elimination (aggregations, distinct counts, etc.)
	 * - Join conditions are properly structured as binary expressions
	 *
	 * POTENTIAL RISKS:
	 * - May affect result cardinality if ranges have different purposes
	 * - Could break queries relying on duplicate detection
	 * - Assumes all identifiers can be safely redirected
	 */
	class SelfJoinOptimizer {
		
		/**
		 * @var BinaryOperationHelper Helper for binary operations
		 */
		private BinaryOperationHelper $binaryHelper;
		
		/**
		 * Constructor
		 */
		public function __construct() {
			$this->binaryHelper = new BinaryOperationHelper();
		}
		
		/**
		 * Main optimization entry point.
		 *
		 * ALGORITHM:
		 * 1. Compare all range pairs (avoiding duplicate comparisons with i >= j check)
		 * 2. Identify functionally equivalent ranges
		 * 3. Merge redundant ranges by redirecting references
		 *
		 * EDGE CASE: Empty ranges array would cause no iterations
		 * EDGE CASE: Single range would cause no comparisons (j never > i)
		 */
		public function optimize(AstRetrieve $ast): void {
			// Fetch the ranges
			$ranges = $ast->getRanges();
			
			// Compare each pair of ranges to identify redundant self-joins
			// Using nested loops with i >= j guard to avoid duplicate comparisons
			// and self-comparison (when i == j)
			foreach ($ranges as $i => $range1) {
				foreach ($ranges as $j => $range2) {
					// Skip duplicate pairs and self-comparison
					// CRITICAL: This prevents range1 === range2 scenarios
					if ($i >= $j) {
						continue;
					}
					
					// Check if ranges represent the same logical entity
					if ($this->areRangesFunctionallyEquivalent($range1, $range2)) {
						// DESTRUCTIVE OPERATION: This modifies the AST structure
						// and may affect subsequent iterations if ranges collection changes
						$this->mergeRedundantRange($ast, $range1, $range2);
					}
				}
			}
		}
		
		/**
		 * Determines if two ranges are functionally equivalent.
		 *
		 * EQUIVALENCE CRITERIA:
		 * 1. Same entity type (table/collection name)
		 * 2. Identity join condition (same field equality)
		 */
		private function areRangesFunctionallyEquivalent(AstRange $range1, AstRange $range2): bool {
			// First check: must be same entity type
			if ($range1->getEntityName() !== $range2->getEntityName()) {
				return false;
			}
			
			// Second check: must have identity join between the ranges
			$join1 = $range1->getJoinProperty();
			$join2 = $range2->getJoinProperty();
			
			// Check both directions - either range can contain the identity join
			return
				($join1 !== null && $this->isIdentityJoin($join1, $range1, $range2)) ||
				($join2 !== null && $this->isIdentityJoin($join2, $range1, $range2));
		}
		
		/**
		 * Checks if a join property represents an identity join between two ranges.
		 *
		 * IDENTITY JOIN DEFINITION:
		 * Same field from both ranges compared for equality (e.g., range1.id = range2.id)
		 *
		 * BIDIRECTIONAL CHECK:
		 * Handles both "range1.field = range2.field" AND "range2.field = range1.field"
		 * since expression trees can have operands in either order.
		 *
		 * ASSUMPTIONS:
		 * - Join property is an AstExpression with binary structure
		 * - Binary operations have accessible left/right operands
		 * - Identifiers have accessible range and field name information
		 *
		 * POTENTIAL ISSUES:
		 * - getNext() returning null would cause $leftFieldName/$rightFieldName to be null
		 * - Comparison would succeed if both are null, potentially false positive
		 * - No validation that the comparison operator is actually equality
		 */
		private function isIdentityJoin($joinProperty, AstRange $range1, AstRange $range2): bool {
			// Must be a structured expression (not primitive value)
			if (!($joinProperty instanceof AstExpression)) {
				return false;
			}
			
			// Extract left and right operands from binary expression
			$left = $this->binaryHelper->getBinaryLeft($joinProperty);
			$right = $this->binaryHelper->getBinaryRight($joinProperty);
			
			// Get field names being compared
			// POTENTIAL NULL REFERENCE: getNext() might return null
			$leftFieldName = $left->getNext() ? $left->getNext()->getName() : null;
			$rightFieldName = $right->getNext() ? $right->getNext()->getName() : null;
			
			// Check both possible orderings of the ranges in the comparison
			// Pattern 1: range1.field = range2.field
			// Pattern 2: range2.field = range1.field (operands swapped)
			return ($left->getRange()->getName() === $range1->getName() &&
					$right->getRange()->getName() === $range2->getName() &&
					$leftFieldName === $rightFieldName) ||
				($left->getRange()->getName() === $range2->getName() &&
					$right->getRange()->getName() === $range1->getName() &&
					$leftFieldName === $rightFieldName);
		}
		
		/**
		 * Merges a redundant range into the kept range and removes it from the query.
		 *
		 * MERGE STRATEGY:
		 * 1. Redirect all references from removeRange to keepRange
		 * 2. Mark removeRange as excluded from join processing
		 *
		 * CRITICAL DECISION: keepRange vs removeRange selection
		 * Currently uses the first range encountered as the keeper.
		 * This may not be optimal - should consider:
		 * - Range with better join conditions
		 * - Range appearing earlier in FROM clause
		 * - Range with fewer dependencies
		 *
		 * SIDE EFFECTS:
		 * - All identifiers pointing to removeRange are redirected
		 * - Join conditions referencing removeRange are updated
		 * - removeRange remains in ranges collection but excluded from joins
		 */
		private function mergeRedundantRange(AstRetrieve $ast, AstRange $keepRange, AstRange $removeRange): void {
			// Redirect all references from removed range to kept range
			$this->replaceRangeReferences($ast, $removeRange, $keepRange);
			
			// Mark the redundant range as excluded from join processing
			// NOTE: Range object remains in collection but won't participate in joins
			$removeRange->setIncludeAsJoin(false);
		}
		
		/**
		 * Replaces all references to oldRange with newRange throughout the AST.
		 *
		 * SCOPE OF REPLACEMENT:
		 * - All identifiers directly referencing the old range
		 * - Join conditions containing references to the old range
		 *
		 * ASSUMPTIONS:
		 * - getAllIdentifiers() returns complete list of range references
		 * - setRange() properly updates identifier binding
		 * - Join condition updates don't create circular references
		 *
		 * POTENTIAL RACE CONDITIONS:
		 * If AST is being modified concurrently, identifier collection
		 * might be stale or miss newly created references.
		 */
		private function replaceRangeReferences(AstRetrieve $ast, AstRange $oldRange, AstRange $newRange): void {
			// Get all identifiers that reference the old range
			$identifiers = $ast->getAllIdentifiers($oldRange);
			
			// Redirect each identifier to point to the new range
			foreach ($identifiers as $identifier) {
				$identifier->setRange($newRange);
			}
			
			// Update join conditions that reference the old range
			$this->updateJoinConditionsForRange($ast, $oldRange, $newRange);
		}
		
		/**
		 * Updates join conditions when a range reference changes.
		 *
		 * PROCESSING SCOPE:
		 * - Join properties of all ranges in the query
		 * - Global WHERE conditions
		 *
		 * RECURSION STRATEGY:
		 * Uses recursive traversal to handle nested expression structures
		 * like ((a.id = b.id) AND (b.name = c.name)) OR (a.status = 'active')
		 *
		 * EDGE CASES:
		 * - Null join properties are safely skipped
		 * - Null global conditions are safely skipped
		 * - Complex nesting levels are handled by recursion
		 */
		private function updateJoinConditionsForRange(AstRetrieve $ast, AstRange $oldRange, AstRange $newRange): void {
			// Update join properties for all ranges
			foreach ($ast->getRanges() as $range) {
				$joinProperty = $range->getJoinProperty();
				
				// Skip ranges without join conditions
				if ($joinProperty === null) {
					continue;
				}
				
				// Recursively update identifiers in the join expression
				$this->updateIdentifiersInExpression($joinProperty, $oldRange, $newRange);
			}
			
			// Update global WHERE conditions
			if ($ast->getConditions() !== null) {
				$this->updateIdentifiersInExpression($ast->getConditions(), $oldRange, $newRange);
			}
		}
		
		/**
		 * Recursively updates identifiers in expression trees.
		 *
		 * TRAVERSAL STRATEGY:
		 * 1. Check if current node is target identifier - update if match
		 * 2. Check if binary operation - recursively process left/right operands
		 * 3. Check for embedded identifiers using reflection-like methods
		 * 4. Check for nested condition structures
		 *
		 * REFLECTION USAGE:
		 * Uses method_exists() to safely check for getIdentifier() and getConditions()
		 * This allows handling different AST node types without strict type checking.
		 *
		 * POTENTIAL INFINITE RECURSION:
		 * If AST contains circular references, this could recurse indefinitely.
		 * No depth limiting or visited node tracking is implemented.
		 *
		 * MISSING CASES:
		 * - Array/collection properties containing identifiers
		 * - Other AST node types with identifier references
		 * - Dynamically generated identifier references
		 */
		private function updateIdentifiersInExpression(AstInterface $expression, AstRange $oldRange, AstRange $newRange): void {
			// Case 1: Direct identifier match - update the range reference
			if ($expression instanceof AstIdentifier && $expression->getRange() === $oldRange) {
				$expression->setRange($newRange);
				return;
			}
			
			// Case 2: Binary operation - recursively process both operands
			if ($this->binaryHelper->isBinaryOperationNode($expression)) {
				$this->updateIdentifiersInExpression($this->binaryHelper->getBinaryLeft($expression), $oldRange, $newRange);
				$this->updateIdentifiersInExpression($this->binaryHelper->getBinaryRight($expression), $oldRange, $newRange);
				return;
			}
			
			// Case 3: Node with embedded identifier property
			// Uses duck typing to check for getIdentifier() method
			if (method_exists($expression, 'getIdentifier')) {
				$identifier = $expression->getIdentifier();
				if ($identifier instanceof AstIdentifier && $identifier->getRange() === $oldRange) {
					$identifier->setRange($newRange);
				}
			}
			
			// Case 4: Node with nested conditions
			// Uses duck typing to check for getConditions() method
			if (method_exists($expression, 'getConditions') && $expression->getConditions() !== null) {
				$this->updateIdentifiersInExpression($expression->getConditions(), $oldRange, $newRange);
			}
		}
	}