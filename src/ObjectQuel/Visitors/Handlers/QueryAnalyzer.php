<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	
	/**
	 * This class encapsulates the complex logic for determining query optimization
	 * strategies by analyzing the AST structure and caching the results. It replaces
	 * the deeply nested conditional logic with clear, testable boolean methods.
	 */
	class QueryAnalyzer {
		
		/** @var AstInterface The AST expression being analyzed */
		private AstInterface $expression;
		
		/** @var array Array of all ranges (table/entity references) found in the expression */
		private array $ranges;
		
		/** @var array Array of all identifier nodes found in the expression */
		private array $identifiers;
		
		// Cached analysis results to avoid repeated computation
		/** @var bool|null Whether this is a single range query (cached) */
		private ?bool $isSingleRange = null;
		
		/** @var bool|null Whether ranges are equivalent (same entity, simple joins) (cached) */
		private ?bool $isEquivalentRange = null;
		
		/** @var bool|null Whether expression only references base range (cached) */
		private ?bool $isBaseRangeReference = null;
		
		/** @var bool|null Whether expression has no join conditions (cached) */
		private ?bool $hasNoJoins = null;
		
		/** @var bool|null Whether any ranges are optional (LEFT JOIN) (cached) */
		private ?bool $hasOptionalRanges = null;
		
		/** @var bool|null Whether all ranges are required and already joined in main query (cached) */
		private ?bool $allRangesRequiredAndJoined = null;
		
		/** @var OptimizationStrategy|null Cache the strategy once computed */
		private ?OptimizationStrategy $optimizationStrategy;
		
		/**
		 * Initializes the query analysis by extracting ranges and identifiers from the expression.
		 * @param AstInterface $expression The AST expression to analyze
		 * @param EntityStore $entityStore Store containing entity-to-table mappings
		 */
		public function __construct(AstInterface $expression, EntityStore $entityStore) {
			$this->expression = $expression;
			$this->ranges = $this->extractAllRanges($expression);
			$this->identifiers = $this->collectIdentifierNodes($expression);
			$this->optimizationStrategy = null;
		}
		
		/**
		 * Gets the array of ranges extracted from the expression.
		 * @return array Array of AstRange objects
		 */
		public function getRanges(): array {
			return $this->ranges;
		}
		
		/**
		 * Gets the original AST expression being analyzed.
		 * @return AstInterface The AST expression
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Determines the optimal execution strategy for this query.
		 * Replaces the multiple boolean methods with a single, clear decision.
		 */
		public function getOptimizationStrategy(): OptimizationStrategy {
			if ($this->optimizationStrategy === null) {
				$this->optimizationStrategy = $this->computeOptimizationStrategy();
			}
			
			return $this->optimizationStrategy;
		}
		
		/**
		 * Computes the optimization strategy using corrected linear flow logic.
		 * FIXED: Reordered conditions and added debug output.
		 */
		private function computeOptimizationStrategy(): OptimizationStrategy {
			// 1. Single range optimization
			if ($this->isSingleRange()) {
				return OptimizationStrategy::constantTrue('Single range query - existence guaranteed');
			}
			
			// 2. Equivalent range optimization
			if ($this->isEquivalentRange()) {
				return OptimizationStrategy::constantTrue('Equivalent ranges with simple equality joins');
			}
			
			// 3. Base range optimization
			if ($this->isBaseRangeReference()) {
				return OptimizationStrategy::constantTrue('Base range reference - always exists');
			}
			
			// 4. No-join optimization
			if ($this->hasNoJoins()) {
				return OptimizationStrategy::simpleExists();
			}
			
			// 5. Cross-entity join check
			if ($this->requiresCrossEntityJoins()) {
				return OptimizationStrategy::subquery();
			}
			
			// 6. Required and joined optimization
			if ($this->allRangesRequiredAndJoined()) {
				return OptimizationStrategy::joinBased();
			}
			
			// 7. Optional range handling
			if ($this->hasOptionalRanges()) {
				return OptimizationStrategy::nullCheck();
			}
			
			// 8. Default fallback
			return OptimizationStrategy::subquery();
		}
		
		/**
		 * Determines if the query requires joins between different entity types
		 * that aren't already established in the main query.
		 * @return bool True if cross-entity joins are needed
		 */
		private function requiresCrossEntityJoins(): bool {
			// Early exit: if we have fewer than 2 ranges, no joins are possible
			if (count($this->ranges) < 2) {
				return false;
			}
			
			// Check if we have different entity types by collecting unique entity names
			$entityTypes = [];
			foreach ($this->ranges as $range) {
				// Use entity name as key to automatically deduplicate
				$entityTypes[$range->getEntityName()] = true;
			}
			
			// If all ranges are the same entity type, this isn't a cross-entity join
			// (would be self-joins or simple queries on single entity)
			if (count($entityTypes) <= 1) {
				return false;
			}
			
			// Now we know we have multiple entity types - check if any require new joins
			// Loop through each range to see if it needs a join that's not already established
			foreach ($this->ranges as $range) {
				// If this range has a join property defined (meaning it needs to be joined)
				// AND that join isn't already present in the main query structure
				if ($range->getJoinProperty() !== null && !$this->isJoinAlreadyInMainQuery($range)) {
					// We found at least one range that needs a new cross-entity join
					return true;
				}
			}
			
			// All cross-entity relationships are already handled in the main query
			return false;
		}
		
		/**
		 * Determines if this is a single range query that can be optimized to constant true.
		 * Single range queries are those where the base query only operates on one entity.
		 * @return bool True if this is a single range query
		 */
		private function isSingleRange(): bool {
			if ($this->isSingleRange === null) {
				$this->isSingleRange = $this->computeIsSingleRange();
			}
			
			return $this->isSingleRange;
		}
		
		/**
		 * Determines if ranges are equivalent (same entity type with simple equality joins).
		 * Equivalent ranges can be optimized because the join conditions are guaranteed to match.
		 * @return bool True if ranges are equivalent
		 */
		private function isEquivalentRange(): bool {
			if ($this->isEquivalentRange === null) {
				$this->isEquivalentRange = $this->computeIsEquivalentRange();
			}
			
			return $this->isEquivalentRange;
		}
		
		/**
		 * Determines if the expression only references the base range (no joins required).
		 * Base range references always exist in the query context and can be optimized.
		 * @return bool True if expression only references base range
		 */
		private function isBaseRangeReference(): bool {
			if ($this->isBaseRangeReference === null) {
				$this->isBaseRangeReference = $this->computeIsBaseRangeReference();
			}
			
			return $this->isBaseRangeReference;
		}
		
		/**
		 * Determines if the expression has no join conditions.
		 * No-join expressions can use simpler existence checks.
		 * @return bool True if no joins are required
		 */
		private function hasNoJoins(): bool {
			if ($this->hasNoJoins === null) {
				$this->hasNoJoins = $this->computeHasNoJoins();
			}
			
			return $this->hasNoJoins;
		}
		
		/**
		 * Determines if any ranges are optional (LEFT JOIN vs INNER JOIN).
		 * Optional ranges can produce NULL values and need special handling.
		 * @return bool True if any ranges are optional
		 */
		private function hasOptionalRanges(): bool {
			if ($this->hasOptionalRanges === null) {
				$this->hasOptionalRanges = $this->computeHasOptionalRanges();
			}
			
			return $this->hasOptionalRanges;
		}
		
		/**
		 * Determines if all ranges are required AND already joined in the main query.
		 * This optimization applies when the main query has already established all needed joins.
		 * @return bool True if all ranges are required and already joined
		 */
		private function allRangesRequiredAndJoined(): bool {
			if ($this->allRangesRequiredAndJoined === null) {
				$this->allRangesRequiredAndJoined = $this->computeAllRangesRequiredAndJoined();
			}
			
			return $this->allRangesRequiredAndJoined;
		}
		
		// ============================================================================
		// PRIVATE COMPUTATION METHODS
		// ============================================================================
		
		/**
		 * Computes whether this is a single range query by checking the base query structure.
		 * Single range queries operate on one entity and can often be optimized to constant true.
		 * @return bool True if this is a single range query
		 */
		private function computeIsSingleRange(): bool {
			if (empty($this->identifiers)) {
				return false;
			}
			
			$queryNode = $this->getBaseQuery($this->expression);
			return $queryNode->isSingleRangeQuery();
		}
		
		/**
		 * Computes whether ranges are equivalent by checking entity types and join conditions.
		 * Handles both multiple range scenarios and single range scenarios.
		 * @return bool True if ranges are equivalent
		 */
		private function computeIsEquivalentRange(): bool {
			if (count($this->ranges) >= 2) {
				return $this->checkMultipleRangeEquivalence();
			}
			
			if (count($this->ranges) === 1) {
				return $this->checkSingleRangeEquivalence();
			}
			
			return false;
		}
		
		/**
		 * Computes whether expression only references the base range (no joins required).
		 * Base ranges have no join properties and always exist in the query context.
		 * @return bool True if only base range is referenced
		 */
		private function computeIsBaseRangeReference(): bool {
			if (count($this->ranges) !== 1) {
				return false;
			}
			
			$range = $this->ranges[0];
			return $range->getJoinProperty() === null;
		}
		
		/**
		 * Computes whether the expression requires any join operations.
		 * Expressions without joins can use simpler optimization strategies.
		 * @return bool True if no joins are required
		 */
		private function computeHasNoJoins(): bool {
			foreach ($this->ranges as $range) {
				if ($range->getJoinProperty() !== null) {
					return false;
				}
			}
			return true;
		}
		
		/**
		 * Computes whether any ranges are optional (LEFT JOIN vs INNER JOIN).
		 * Optional ranges can produce NULL values and affect optimization strategies.
		 * @return bool True if any ranges are optional
		 */
		private function computeHasOptionalRanges(): bool {
			foreach ($this->ranges as $range) {
				if (!$range->isRequired()) {
					return true;
				}
			}
			return false;
		}
		
		/**
		 * Computes whether all ranges are required AND already established in the main query.
		 * This optimization applies when the main query has already created all needed joins.
		 * @return bool True if all ranges are required and already joined
		 */
		private function computeAllRangesRequiredAndJoined(): bool {
			foreach ($this->ranges as $range) {
				if (!$range->isRequired() || !$this->isJoinAlreadyInMainQuery($range)) {
					return false;
				}
			}
			return true;
		}
		
		// ============================================================================
		// RANGE EQUIVALENCE ANALYSIS HELPERS
		// ============================================================================
		
		/**
		 * Checks equivalence for multiple ranges (2 or more).
		 * All ranges must be same entity type with simple equality joins.
		 * @return bool True if all ranges are equivalent
		 */
		private function checkMultipleRangeEquivalence(): bool {
			// Track the entity name from the first range to compare against all others
			$firstEntityName = null;
			
			// Iterate through all ranges to verify they meet equivalence criteria
			foreach ($this->ranges as $range) {
				// Get the current range's entity name for comparison
				$entityName = $range->getEntityName();
				
				// On first iteration, store the entity name as our baseline
				if ($firstEntityName === null) {
					$firstEntityName = $entityName;
				} elseif ($firstEntityName !== $entityName) {
					// If any range has a different entity type, ranges are not equivalent
					// This ensures all ranges operate on the same database table/entity
					return false;
				}
				
				// Check if this range requires a join operation
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty !== null && !$this->isSimpleEqualityJoin($joinProperty)) {
					// If a join exists but isn't a simple equality join (e.g., complex conditions,
					// subqueries, or non-equality operators), the ranges are not equivalent
					// Only simple equality joins (like id = foreign_id) maintain equivalence
					return false;
				}
			}
			
			// All ranges passed the equivalence tests:
			// 1. Same entity type across all ranges
			// 2. All joins (if any) are simple equality operations
			return true;
		}
		
		/**
		 * Checks equivalence for single range scenarios.
		 * Single range must join to another range of the same entity type with simple equality.
		 * @return bool True if single range is equivalent to its join target
		 */
		private function checkSingleRangeEquivalence(): bool {
			// Get the single range we're analyzing
			$singleRange = $this->ranges[0];
			$joinProperty = $singleRange->getJoinProperty();
			
			// Must have a join property, and it must be a simple equality join
			if (!$joinProperty || !$this->isSimpleEqualityJoin($joinProperty)) {
				return false;
			}
			
			// Extract all identifier nodes from the join condition (e.g., "c.id = d.id" gives us c and d)
			$joinIdentifiers = $this->collectIdentifierNodes($joinProperty);
			
			if (count($joinIdentifiers) !== 2) {
				// Simple equality joins should have exactly 2 identifiers (left and right side)
				return false;
			}
			
			// Look for the other range that this single range is joining to
			foreach ($joinIdentifiers as $identifier) {
				// Fetch the range
				$range = $identifier->getRange();
				
				// If we find a range that's different from our single range
				if ($range && $range->getName() !== $singleRange->getName()) {
					// Check if the join target has the same entity type as our single range
					// This ensures we're joining the same entity to itself (self-join equivalence)
					return $range->getEntityName() === $singleRange->getEntityName();
				}
			}
			
			// No valid join target found
			return false;
		}
		
		/**
		 * Determines if a join property represents a simple equality join between same entity types.
		 * Simple equality joins are patterns like "c.id = d.id" where both sides reference the same field.
		 * @param AstInterface $joinProperty The join condition to analyze
		 * @return bool True if this is a simple equality join between same entities
		 */
		private function isSimpleEqualityJoin(AstInterface $joinProperty): bool {
			// Collect all identifier nodes from the join expression (e.g., c.id, d.id from "c.id = d.id")
			$identifiers = $this->collectIdentifierNodes($joinProperty);
			
			// Simple equality joins should have exactly 2 identifiers (left side = right side)
			if (count($identifiers) !== 2) {
				return false;
			}
			
			// Get the range objects for both sides of the join
			$leftRange = $identifiers[0]->getRange();
			$rightRange = $identifiers[1]->getRange();
			
			// Both sides must have valid range references
			if ($leftRange === null || $rightRange === null) {
				return false;
			}
			
			// For a simple equality join between same entities:
			// 1. Both ranges must reference the same entity type (same table/class)
			// 2. Both identifiers must reference the same field name (e.g., both "id")
			// This pattern like "user1.id = user2.id" indicates equivalent data access
			return $leftRange->getEntityName() === $rightRange->getEntityName() &&
				$identifiers[0]->getName() === $identifiers[1]->getName();
		}
		
		/**
		 * Determines if a range's join is already established in the main query.
		 * Base ranges (no join property) are always in main query.
		 * Ranges with includeAsJoin() flag are explicitly included.
		 * @param AstRange $range The range to check for existing joins
		 * @return bool True if the join is already available in main query
		 */
		private function isJoinAlreadyInMainQuery(AstRange $range): bool {
			return $range->getJoinProperty() === null || $range->includeAsJoin();
		}
		
		// ============================================================================
		// AST TRAVERSAL AND ANALYSIS METHODS
		// ============================================================================
		
		/**
		 * Traverses the AST tree to find all AstIdentifier nodes.
		 * Identifiers represent references to entity properties and ranges in the query.
		 * @param AstInterface $ast Root AST node to search
		 * @return AstIdentifier[] Array of all identifier nodes found
		 */
		private function collectIdentifierNodes(AstInterface $ast): array {
			$visitor = new CollectNodes(AstIdentifier::class);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Extracts all unique ranges from the expression by analyzing identifier nodes.
		 * FIXED: Also extracts ranges from join properties to get complete range set.
		 * @param AstInterface $expression Expression to extract ranges from
		 * @return AstRange[] Array of unique range objects
		 */
		private function extractAllRanges(AstInterface $expression): array {
			$identifiers = $this->collectIdentifierNodes($expression);
			$allRanges = $this->getAllRanges($identifiers);
			
			// FIXED: Also collect ranges from join properties
			$additionalRanges = [];
			
			foreach ($allRanges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty !== null) {
					$joinIdentifiers = $this->collectIdentifierNodes($joinProperty);
					$joinRanges = $this->getAllRanges($joinIdentifiers);
					$additionalRanges = array_merge($additionalRanges, $joinRanges);
				}
			}
			
			// Merge and deduplicate all ranges
			return $this->deduplicateRanges(array_merge($allRanges, $additionalRanges));
		}
		
		/**
		 * Removes duplicate ranges based on range name.
		 * @param AstRange[] $ranges Array of ranges that may contain duplicates
		 * @return AstRange[] Array of unique ranges
		 */
		private function deduplicateRanges(array $ranges): array {
			$result = [];
			$seen = [];
			
			foreach ($ranges as $range) {
				$rangeName = $range->getName();
				
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		/**
		 * Gets unique ranges from an array of identifiers, filtering for database ranges only.
		 * Ensures each range appears only once in the result set.
		 * @param AstIdentifier[] $identifiers Array of identifier nodes
		 * @return AstRange[] Array of unique ranges
		 */
		private function getAllRanges(array $identifiers): array {
			$result = [];
			$seen = [];
			
			foreach ($identifiers as $identifier) {
				$range = $identifier->getRange();
				
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		/**
		 * Finds the root AstRetrieve node by traversing up the AST hierarchy.
		 * The retrieve node represents the main query structure.
		 * Assumes that there is always an AstRetrieve parent in the hierarchy.
		 * @param AstInterface $ast Starting AST node
		 * @return AstRetrieve The root retrieve node
		 */
		private function getBaseQuery(AstInterface $ast): AstRetrieve {
			$current = $ast;
			
			if ($current instanceof AstRetrieve) {
				return $current;
			}
			
			while ($parent = $current->getParent()) {
				if ($parent instanceof AstRetrieve) {
					return $parent;
				}
				
				$current = $parent;
			}
			
			// This should never be reached given the assumption
			throw new \RuntimeException('No AstRetrieve parent found in AST hierarchy');
		}
	}