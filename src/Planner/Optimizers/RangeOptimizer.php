<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseMaterialized;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\Planner\Visitors\CollectRanges;
	use Quellabs\ObjectQuel\Planner\Visitors\DetectNonNullableField;
	use Quellabs\ObjectQuel\Planner\Helpers\BinaryOperationHelper;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Handles range-specific optimizations including required annotations
	 * and unused LEFT JOIN removal.
	 *
	 * This optimizer performs two main functions:
	 * 1. Marks ranges as required to convert LEFT JOINs to INNER JOINs when appropriate
	 * 2. Removes unused LEFT JOIN ranges to reduce query complexity
	 *
	 * Performance Impact:
	 * - INNER JOINs are typically faster than LEFT JOINs
	 * - Removing unused JOINs reduces query execution time and memory usage
	 * - Annotation-based optimization ensures semantic correctness
	 */
	class RangeOptimizer {
		
		/** @var EntityStore Provides access to entity metadata and annotations */
		private EntityStore $entityStore;
		
		/**
		 * Initialize optimizer with required dependencies
		 * @param EntityManager $entityManager Provides access to entity metadata store
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
		}
		
		/**
		 * Main optimization entry point - applies all range optimizations
		 *
		 * Optimization Order Matters:
		 * 1. First handle trivial single-range case (performance optimization)
		 * 2. Then analyze entity relationships for semantic optimizations
		 *
		 * Note: removeUnusedLeftJoinRanges() should be called separately after
		 * all other optimizations to ensure we don't remove ranges that might
		 * become required through other optimization passes.
		 *
		 * @param AstRetrieve $ast The AST to optimize
		 * @throws EntityResolutionException
		 * @throws TransformationException
		 */
		public function optimize(AstRetrieve $ast, PlanLogInterface $log = new NullPlanLog()): void {
			// First, mark single ranges as required (trivial case)
			// This is a quick win: single table queries can't use LEFT JOINs anyway
			$this->setOnlyRangeToRequired($ast, $log);
			
			// Then check for annotation-based requirements
			// This uses ORM metadata to determine semantic requirements
			$this->setRangesRequiredThroughAnnotations($ast, $log);
		}
		
		/**
		 * Sets the single range as required when exactly one range exists.
		 *
		 * Optimization Rationale:
		 * - Single table queries have no JOINs, so the concept of "required" is moot
		 * - However, marking it as required maintains consistency in the AST
		 * - Prevents downstream code from incorrectly treating it as optional
		 *
		 * @param AstRetrieve $ast The AST containing ranges
		 */
		private function setOnlyRangeToRequired(AstRetrieve $ast, PlanLogInterface $log): void {
			$ranges = $ast->getRanges();
			
			// Simple case: if there's only one table, it must be required
			// (A query must return results from at least one table)
			if (count($ranges) === 1) {
				// Set only range required for internal consistency reasons
				$ranges[0]->setRequired();
			}
		}
		
		/**
		 * Sets ranges as required based on RequiredRelation annotations.
		 *
		 * This method implements semantic optimization by analyzing ORM annotations.
		 *
		 * Key Concepts:
		 * - LEFT JOIN: Returns all rows from left table, matched rows from right (or NULL)
		 * - INNER JOIN: Returns only rows that have matches in both tables
		 * - RequiredRelation annotation indicates a relationship that must always exist
		 *
		 * Optimization Logic:
		 * - If an entity has a RequiredRelation annotation to another entity
		 * - AND there's a LEFT JOIN to that entity in the query
		 * - THEN convert it to INNER JOIN (mark range as required)
		 *
		 * @param AstRetrieve $ast The AST to analyze
		 * @throws EntityResolutionException
		 * @throws TransformationException
		 */
		private function setRangesRequiredThroughAnnotations(AstRetrieve $ast, PlanLogInterface $log): void {
			// Get the main table (the one without a JOIN condition)
			// This is our starting point - the FROM table in SQL terms
			$mainRange = $ast->getMainDatabaseRange();
			
			// No main range found - malformed query or subquery scenario
			if ($mainRange === null) {
				return;
			}
			
			// Check each joined range for required annotations
			foreach ($ast->getRanges() as $range) {
				// Only include database ranges
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Skip ranges that don't have proper binary join conditions
				// (e.g., CROSS JOINs, subqueries, etc.)
				if (!$this->shouldSetRangeRequired($range)) {
					continue;
				}
				
				// Extract the join condition components
				// JOIN conditions are typically: main_table.foreign_key = joined_table.primary_key
				$joinProperty = $range->getJoinProperty();
				
				// shouldSetRangeRequired() guarantees $joinProperty is a non-null AstExpression,
				// but PHPStan cannot track that invariant across method boundaries.
				if (!$joinProperty instanceof NodeBinary) {
					continue;
				}
				
				$left = BinaryOperationHelper::getBinaryLeft($joinProperty);
				$right = BinaryOperationHelper::getBinaryRight($joinProperty);
				
				// shouldSetRangeRequired() already verified both sides are AstIdentifier instances,
				// but PHPStan cannot track this guarantee across method boundaries.
				// Re-check here to maintain type safety without relying on assert.
				if (!$left instanceof AstIdentifier || !$right instanceof AstIdentifier) {
					continue;
				}
				
				// Normalize join direction: ensure range entity is on the left side
				// This simplifies the annotation checking logic below
				if ($right->getEntityName() === $range->getEntityName()) {
					[$left, $right] = [$right, $left];
				}
				
				// Verify this range is actually part of the join condition
				// Safety check to ensure we're analyzing the correct relationship
				if ($left->getEntityName() === $range->getEntityName()) {
					$this->checkAndSetRangeRequired($mainRange, $range, $left, $right, $log);
				}
			}
		}
		
		/**
		 * Collects all ranges (table references) that are actually used in the query
		 * by traversing the AST nodes for SELECT values, ORDER BY clauses, and WHERE conditions
		 * @param AstRetrieve $ast The query AST to analyze
		 * @param bool $traverseSubqueries
		 * @return array<int, AstRange> Array of range nodes that are referenced in the query
		 */
		private function getUsedRanges(AstRetrieve $ast, bool $traverseSubqueries = true): array {
			// Initialize visitor pattern to collect range references
			$visitor = new CollectRanges($traverseSubqueries);
			
			// Traverse all SELECT clause values to find referenced ranges
			foreach ($ast->getValues() as $value) {
				$value->accept($visitor);
			}
			
			// Traverse all ORDER BY clause expressions to find referenced ranges
			foreach ($ast->getSort() as $value) {
				$value['ast']->accept($visitor);
			}
			
			// Traverse WHERE clause conditions to find referenced ranges
			$ast->getConditions()?->accept($visitor);
			
			// Return collected range references
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Builds an indexed set of range names that are considered "in use" for pruning decisions.
		 *
		 * Centralizes the definition of what counts as usage so that both removeUnusedLeftJoinRanges()
		 * and removeUnusedTemporaryRanges() apply identical criteria. Future changes to usage
		 * semantics (e.g. adding GROUP BY traversal) only need to be made here.
		 *
		 * @param AstRetrieve $ast The query AST to analyze
		 * @param bool $traverseSubqueries Whether to descend into subqueries when collecting ranges
		 * @return array<string, true> Hash set of range names keyed for O(1) lookup
		 */
		private function collectUsedRangeNames(AstRetrieve $ast, bool $traverseSubqueries): array {
			$allUsedRanges = array_merge(
				$this->getUsedRanges($ast, $traverseSubqueries),
				$this->getRangesUsedInJoinConditions($ast)
			);
			
			return array_fill_keys(array_map(fn($r) => $r->getName(), $allUsedRanges), true);
		}
		
		/**
		 * Removes unused LEFT JOIN ranges from the query AST to optimize the query.
		 * Keeps the main range, required joins, and any ranges actually referenced
		 * in SELECT, WHERE, or ORDER BY clauses.
		 * @param AstRetrieve $ast The query AST to optimize
		 * @param bool $traverseSubqueries
		 * @return void Modifies the AST in place
		 */
		public function removeUnusedLeftJoinRanges(AstRetrieve $ast, bool $traverseSubqueries = true, PlanLogInterface $log = new NullPlanLog()): void {
			$result = [];
			$mainRange = $ast->getMainDatabaseRange();
			$usedRangeNames = $this->collectUsedRangeNames($ast, $traverseSubqueries);
			
			foreach ($ast->getRanges() as $range) {
				if ($range === $mainRange || $range->isRequired() || isset($usedRangeNames[$range->getName()])) {
					$result[] = $range;
				} else {
					$log->note('optimizer', 'join', 'UNUSED_JOIN_REMOVED',
						"Range '{$range->getName()}' is not referenced in SELECT, WHERE, or ORDER BY; removed",
						$range->getName()
					);
				}
			}
			
			$ast->setRanges($result);
		}
		
		/**
		 * Removes unused temporary ranges from the query.
		 *
		 * A temporary range is considered unused if:
		 * - It's not referenced in the RETRIEVE values
		 * - It's not referenced in the WHERE clause
		 * - It's not referenced in ORDER BY
		 * - It's not referenced in any other range's via clause
		 *
		 * This is different from removeUnusedLeftJoinRanges() because temporary ranges
		 * may be main ranges (no join type), but still be completely unused.
		 *
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		public function removeUnusedTemporaryRanges(AstRetrieve $ast, PlanLogInterface $log = new NullPlanLog()): void {
			$usedRangeNames = $this->collectUsedRangeNames($ast, false);
			$result = [];
			
			foreach ($ast->getRanges() as $range) {
				// Keep non-temporary ranges unconditionally
				if (!($range instanceof AstRangeDatabaseTempTable) && !($range instanceof AstRangeDatabaseMaterialized)) {
					$result[] = $range;
					continue;
				}
				
				// For temporary ranges, keep only those actually referenced
				if (isset($usedRangeNames[$range->getName()])) {
					$result[] = $range;
					continue;
				}
				
				$log->note('optimizer', 'range', 'UNUSED_TEMP_REMOVED',
					"Range '{$range->getName()}' is not referenced in SELECT, WHERE, or ORDER BY; removed",
					$range->getName()
				);
			}
			
			$ast->setRanges($result);
		}
		
		/**
		 * Collects ranges used in join conditions (via clauses)
		 * @param AstRetrieve $ast
		 * @return array<int, AstRange>
		 */
		private function getRangesUsedInJoinConditions(AstRetrieve $ast): array {
			$visitor = new CollectRanges(false);
			
			foreach ($ast->getRanges() as $range) {
				$range->getJoinProperty()?->accept($visitor);
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Determines if a range should be checked for required relation annotations.
		 *
		 * This method filters ranges to only those that can benefit from annotation analysis.
		 *
		 * Criteria for Analysis:
		 * - Range must have a join property (it's actually joined to something)
		 * - Join property must be a binary expression (e.g., "a.id = b.foreign_id")
		 * - Both sides of the expression must be identifiers (table.column references)
		 *
		 * Why These Criteria?
		 * - Only binary equi-joins can be optimized with annotation data
		 * - Complex expressions or functions in JOIN conditions are hard to analyze
		 * - Non-identifier expressions (literals, functions) don't map to entity relationships
		 *
		 * @param AstRange $range The range to check
		 * @return bool True if the range should be checked for requirements
		 */
		private function shouldSetRangeRequired(AstRange $range): bool {
			// Fetch join property
			$joinProperty = $range->getJoinProperty();
			
			// Check for the standard equi-join pattern: table1.column = table2.column
			return
				$joinProperty instanceof AstExpression &&
				$joinProperty->getLeft() instanceof AstIdentifier &&
				$joinProperty->getRight() instanceof AstIdentifier;
		}
		
		/**
		 * Checks annotations and sets range as required if a matching RequiredRelation is found.
		 *
		 * This is the core of semantic optimization using ORM metadata.
		 *
		 * Process Flow:
		 * 1. Determine the direction of the relationship (which table owns the foreign key)
		 * 2. Extract property names from both sides of the JOIN condition
		 * 3. Look up entity annotations for the owning entity (or analyze temporary range structure)
		 * 4. Search for RequiredRelation annotations that match this relationship
		 * 5. If found, mark the range as required (converting LEFT JOIN to INNER JOIN)
		 *
		 * Relationship Direction Examples:
		 * - User.department_id = Department.id (User owns the relationship)
		 * - Department.id = User.department_id (same relationship, different order)
		 *
		 * Annotation Matching:
		 * - Target entity must match the joined table
		 * - Relation column must match the foreign key field
		 * - Inverse property must match the back-reference field
		 *
		 * Temporary Range Handling:
		 * - For subqueries, uses nullability analysis instead of annotations
		 * - Traces through the subquery structure to find source field nullability
		 * - Converts LEFT JOIN to INNER JOIN if the joined field is non-nullable
		 *
		 * @param AstRangeDatabase $mainRange The main table range
		 * @param AstRangeDatabase $range The range being checked for requirement
		 * @param AstIdentifier $left Left side of the join condition
		 * @param AstIdentifier $right Right side of the join condition
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function checkAndSetRangeRequired(
			AstRangeDatabase $mainRange,
			AstRangeDatabase $range,
			AstIdentifier    $left,
			AstIdentifier    $right,
			PlanLogInterface $log
		): void {
			// Determine relationship direction by checking which side references the main range
			// This tells us which entity "owns" the relationship (has the foreign key)
			$isMainRange = $right->getRange() === $mainRange;
			
			// Extract property and entity names based on join direction
			// These will be used to match against annotation metadata
			$ownEntityName = $isMainRange ? $right->getEntityName() : $left->getEntityName();
			$ownPropertyName = $isMainRange ? $right->getName() : $left->getName();
			$relatedPropertyName = $isMainRange ? $left->getName() : $right->getName();
			$relatedEntityName = $isMainRange ? $left->getEntityName() : $right->getEntityName();
			
			// Handle temporary ranges (subqueries) with nullability analysis
			// Temporary tables have no entity metadata or annotations.
			// Also bail out if $relatedEntityName is null, since hasMatchingRequiredRelationAnnotation()
			// requires a non-null string and getEntityName() can return null.
			if (empty($ownEntityName) || $relatedEntityName === null) {
				$this->checkTemporaryRangeRequired($range, $isMainRange, $left, $right, $log);
				return;
			}
			
			// Check 1: annotation on the owning entity (holds the FK / relationColumn)
			if ($this->hasMatchingRequiredRelationAnnotation($ownEntityName, $relatedEntityName, $ownPropertyName, $relatedPropertyName)) {
				// Set range required
				$range->setRequired();
				
				// Add note to log
				$log->note('optimizer', 'join', 'ANNOTATION_INNER',
					"Range '{$range->getName()}' has @RequiredRelation on '{$ownPropertyName}'; forced INNER JOIN",
					$range->getName()
				);
				
				return;
			}
			
			// Check 2: annotation on the related entity (declared from the inverse side).
			// When the annotation is placed on the joined entity rather than the main entity,
			// the roles of ownPropertyName and relatedPropertyName are swapped.
			if ($this->hasMatchingRequiredRelationAnnotation($relatedEntityName, $ownEntityName, $relatedPropertyName, $ownPropertyName)) {
				// Set range required
				$range->setRequired();
				
				// Add note to log
				$log->note('optimizer', 'join', 'ANNOTATION_INNER',
					"Range '{$range->getName()}' has @RequiredRelation on '{$relatedPropertyName}' (inverse side); forced INNER JOIN",
					$range->getName()
				);
			} else {
				$log->note('optimizer', 'join', 'LEFT_UNCHANGED',
					"Range '{$range->getName()}' has no @RequiredRelation annotation; kept as LEFT JOIN",
					$range->getName()
				);
			}
		}
		
		/**
		 * Checks if a temporary range (subquery) join should be marked as required.
		 *
		 * Uses the ContainsNonNullableFieldForRangeTemporary visitor to determine
		 * if the joined field is non-nullable. If so, converts LEFT JOIN to INNER JOIN.
		 *
		 * Logic:
		 * - Identifies which side of the join is the temporary range
		 * - Uses visitor pattern to check if the field being joined is non-nullable
		 * - Non-nullable fields in join conditions make LEFT JOIN equivalent to INNER JOIN
		 *
		 * @param AstRange $range The range being checked
		 * @param bool $isMainRange Whether the main range is on the right side
		 * @param AstIdentifier $left Left side of join condition
		 * @param AstIdentifier $right Right side of join condition
		 */
		private function checkTemporaryRangeRequired(
			AstRange         $range,
			bool             $isMainRange,
			AstIdentifier    $left,
			AstIdentifier    $right,
			PlanLogInterface $log
		): void {
			// Identify which side contains the temporary range
			$joinedRange = $isMainRange ? $right->getRange() : $left->getRange();
			
			// Only process if it's a materialized or temp table range
			if (
				!$joinedRange instanceof AstRangeDatabaseTempTable &&
				!$joinedRange instanceof AstRangeDatabaseMaterialized
			) {
				return;
			}
			
			// Use visitor to check field nullability
			$visitor = new DetectNonNullableField(
				$joinedRange->getName(),
				$this->entityStore,
				$joinedRange->getQuery(),
			);
			
			// The visitor will analyze if this field reference is non-nullable
			$testIdentifier = $isMainRange ? $right : $left;
			$testIdentifier->accept($visitor);
			
			// Non-nullable field in join condition = safe to convert to INNER JOIN
			if ($visitor->isNonNullable()) {
				// Set required
				$range->setRequired();
				
				// Add note to the log
				$log->note('optimizer', 'join', 'TEMP_RANGE_INNER',
					"Range '{$range->getName()}' joins on a non-nullable field; LEFT JOIN promoted to INNER JOIN",
					$range->getName()
				);
			}
		}
		
		/**
		 * Searches an entity's annotations for a RequiredRelation that matches the
		 * described join relationship. Checks for the presence of @RequiredRelation
		 * on a property and then verifies the accompanying @ManyToOne or @OneToOne
		 * annotation matches the expected entity, FK column, and inverse property.
		 *
		 * @param string $entityName Entity whose annotations are searched
		 * @param string $relatedEntityName Entity on the other side of the join
		 * @param string $ownPropertyName FK property on $entityName
		 * @param string $relatedPropertyName Back-reference property on $relatedEntityName
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function hasMatchingRequiredRelationAnnotation(
			string $entityName,
			string $relatedEntityName,
			string $ownPropertyName,
			string $relatedPropertyName
		): bool {
			// Load all annotations for the entity, grouped by property
			$metadata = $this->entityStore->getMetadata($entityName);
			
			foreach ($metadata->getAnnotations() as $annotations) {
				// Both flags must be set on the same property for a match:
				// @RequiredRelation marks the relation as non-nullable, and the
				// relation annotation itself must confirm the correct join columns
				$hasRequiredRelation = false;
				$hasMatchingRelation = false;
				
				foreach ($annotations as $annotation) {
					// Track whether this property carries @RequiredRelation
					if ($annotation instanceof RequiredRelation) {
						$hasRequiredRelation = true;
						continue;
					}
					
					// Only ManyToOne and OneToOne annotations own a FK column —
					// check that this annotation points to the right entity and column
					if (
						($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
						$annotation->getTargetEntity() === $relatedEntityName &&
						$annotation->getRelationColumn() === $ownPropertyName
					) {
						// resolveTargetProperty handles both ManyToOne (inversedBy or PK fallback)
						// and OneToOne (inversedBy or PK fallback) transparently.
						$resolvedInversedBy = $this->entityStore->resolveTargetProperty($annotation);
						
						// Confirm the back-reference property matches what the join expects
						if ($resolvedInversedBy === $relatedPropertyName) {
							$hasMatchingRelation = true;
						}
					}
					
					// Short-circuit as soon as both flags are confirmed on the same property
					if ($hasRequiredRelation && $hasMatchingRelation) {
						return true;
					}
				}
			}
			
			return false;
		}
	}