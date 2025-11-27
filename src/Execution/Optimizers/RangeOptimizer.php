<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	use Quellabs\ObjectQuel\Execution\Support\BinaryOperationHelper;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRangeTemporary;
	
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
		 */
		public function optimize(AstRetrieve $ast): void {
			// First, mark single ranges as required (trivial case)
			// This is a quick win: single table queries can't use LEFT JOINs anyway
			$this->setOnlyRangeToRequired($ast);
			
			// Then check for annotation-based requirements
			// This uses ORM metadata to determine semantic requirements
			$this->setRangesRequiredThroughAnnotations($ast);
		}
		
		/**
		 * Normalizes query structure when temporary ranges would incorrectly become the main range.
		 *
		 * Problem:
		 * When a temporary range has no via clause and an entity range does, the temporary range
		 * becomes the main range (FROM clause). This is semantically backwards - entity tables
		 * should be the primary source with temporary ranges joined to them.
		 *
		 * Solution:
		 * For the simple case of one temporary + one entity range, swap the via clause to make
		 * the entity range the main range.
		 *
		 * Before normalization:
		 *   range of c is (subquery)                 // becomes main (wrong)
		 *   range of d is PostEntity via d.id=c.id   // becomes joined
		 *   SQL: FROM temp_c LEFT JOIN posts d ON d.id=c.id
		 *
		 * After normalization:
		 *   range of c is (subquery) via d.id=c.id   // becomes joined
		 *   range of d is PostEntity                 // becomes main (correct)
		 *   SQL: FROM posts d LEFT JOIN temp_c ON d.id=c.id
		 *
		 * The required status is also transferred to maintain correct INNER/LEFT JOIN semantics.
		 *
		 * Limitation:
		 * Currently only handles the simple case of exactly two ranges. Complex scenarios with
		 * multiple temporary ranges or multi-range via clauses are not yet supported.
		 *
		 * @param AstRetrieve $ast The query AST to normalize
		 */
		public function normalizeTemporaryRangeStructure(AstRetrieve $ast): void {
			$ranges = $ast->getRanges();
			
			// Only handle the simple case: exactly two ranges
			if (count($ranges) !== 2) {
				return;
			}
			
			// Check if first range is temporary and second is entity with via clause
			if (
				$ranges[0] instanceof AstRangeDatabase &&
				$ranges[0]->containsQuery() &&
				$ranges[1] instanceof AstRangeDatabase &&
				$ranges[1]->getJoinProperty() !== null
			) {
				// Swap the via clause from entity to temporary range
				$tmp = $ranges[1]->getJoinProperty();
				$ranges[0]->setJoinProperty($tmp);
				$ranges[1]->setJoinProperty(null);
				
				// Transfer the required status to maintain INNER/LEFT JOIN semantics
				// The entity range (now main) becomes required
				// The temporary range inherits the previous required status
				$ranges[0]->setRequired($ranges[1]->isRequired());
				$ranges[1]->setRequired();
			}
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
		private function setOnlyRangeToRequired(AstRetrieve $ast): void {
			$ranges = $ast->getRanges();
			
			// Simple case: if there's only one table, it must be required
			// (A query must return results from at least one table)
			if (count($ranges) === 1) {
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
		 */
		private function setRangesRequiredThroughAnnotations(AstRetrieve $ast): void {
			// Get the main table (the one without a JOIN condition)
			// This is our starting point - the FROM table in SQL terms
			$mainRange = $ast->getMainDatabaseRange();
			
			// No main range found - malformed query or subquery scenario
			if ($mainRange === null) {
				return;
			}
			
			// Check each joined range for required annotations
			foreach ($ast->getRanges() as $range) {
				// Skip ranges that don't have proper binary join conditions
				// (e.g., CROSS JOINs, subqueries, etc.)
				if (!$this->shouldSetRangeRequired($range)) {
					continue;
				}
				
				// Extract the join condition components
				// JOIN conditions are typically: main_table.foreign_key = joined_table.primary_key
				$joinProperty = $range->getJoinProperty();
				$left = BinaryOperationHelper::getBinaryLeft($joinProperty);
				$right = BinaryOperationHelper::getBinaryRight($joinProperty);
				
				// Normalize join direction: ensure range entity is on the left side
				// This simplifies the annotation checking logic below
				// @phpstan-ignore-next-line method.notFound
				if ($right->getEntityName() === $range->getEntityName()) {
					[$left, $right] = [$right, $left];
				}
				
				// Verify this range is actually part of the join condition
				// Safety check to ensure we're analyzing the correct relationship
				// @phpstan-ignore-next-line method.notFound
				if ($left->getEntityName() === $range->getEntityName()) {
					$this->checkAndSetRangeRequired($mainRange, $range, $left, $right);
				}
			}
		}
		
		/**
		 * Collects all ranges (table references) that are actually used in the query
		 * by traversing the AST nodes for SELECT values, ORDER BY clauses, and WHERE conditions
		 * @param AstRetrieve $ast The query AST to analyze
		 * @param bool $traverseSubQueries
		 * @return array Array of range nodes that are referenced in the query
		 */
		private function getUsedRanges(AstRetrieve $ast, bool $traverseSubQueries=true): array {
			// Initialize visitor pattern to collect range references
			$visitor = new CollectRanges($traverseSubQueries);
			
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
		 * Removes unused LEFT JOIN ranges from the query AST to optimize the query.
		 * Keeps the main range, required joins, and any ranges actually referenced
		 * in SELECT, WHERE, or ORDER BY clauses.
		 * @param AstRetrieve $ast The query AST to optimize
		 * @param bool $traverseSubqueries
		 * @return void Modifies the AST in place
		 */
		public function removeUnusedLeftJoinRanges(AstRetrieve $ast, bool $traverseSubqueries=true): void {
			$result = [];
			$mainRange = $ast->getMainDatabaseRange();
			$usedRanges = $this->getUsedRanges($ast, $traverseSubqueries);
			
			// NEW: Also collect ranges used in join conditions
			$joinRanges = $this->getRangesUsedInJoinConditions($ast);
			
			foreach ($ast->getRanges() as $range) {
				if ($range === $mainRange || $range->isRequired()) {
					$result[] = $range;
					continue;
				}
				
				// Check both query usage AND join condition usage
				$isUsed = false;
				
				foreach (array_merge($usedRanges, $joinRanges) as $usedRange) {
					if ($usedRange->getName() === $range->getName()) {
						$isUsed = true;
						break;
					}
				}
				
				if ($isUsed) {
					$result[] = $range;
				}
			}
			
			$ast->setRanges($result);
		}
		
		/**
		 * Collects ranges used in join conditions (via clauses)
		 * @param AstRetrieve $ast
		 * @return array
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
		 */
		private function checkAndSetRangeRequired(
			AstRangeDatabase $mainRange,
			AstRangeDatabase $range,
			AstIdentifier    $left,
			AstIdentifier    $right
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
			// Temporary tables have no entity metadata or annotations
			if (empty($ownEntityName)) {
				$this->checkTemporaryRangeRequired($range, $isMainRange, $left, $right);
				return;
			}
			
			// Get all annotations for the entity that owns the relationship
			// Annotations are grouped by property/method they're applied to
			$entityAnnotations = $this->entityStore->getAnnotations($ownEntityName);
			
			// Search through all annotation groups for this entity
			foreach ($entityAnnotations as $annotations) {
				// Performance optimization: quick check for RequiredRelation annotations
				// Avoids detailed processing if no relevant annotations exist
				if (!$this->containsRequiredRelationAnnotation($annotations->toArray())) {
					continue;
				}
				
				// Check each annotation in the current group
				foreach ($annotations as $annotation) {
					// Test if this annotation requires the current relationship
					if ($this->isMatchingRequiredRelation($annotation, $relatedEntityName, $ownPropertyName, $relatedPropertyName)) {
						// Found a matching required relation - optimize the query
						$range->setRequired();
						
						// Early exit: one matching annotation is sufficient
						// Multiple annotations for the same relationship would be redundant
						return;
					}
				}
			}
			
			// No matching RequiredRelation annotation found - leave as LEFT JOIN
			// This preserves the original query semantics
		}
		
		/**
		 * Checks if a temporary range (subquery) join should be marked as required.
		 *
		 * Uses the existing ContainsNonNullableFieldForRangeTemporary visitor to determine
		 * if the joined field is non-nullable. If so, converts LEFT JOIN to INNER JOIN.
		 *
		 * Logic:
		 * - Identifies which side of the join is the temporary range
		 * - Uses visitor pattern to check if the field being joined is non-nullable
		 * - Non-nullable fields in join conditions make LEFT JOIN equivalent to INNER JOIN
		 *
		 * @param AstRangeDatabase $range The range being checked
		 * @param bool $isMainRange Whether the main range is on the right side
		 * @param AstIdentifier $left Left side of join condition
		 * @param AstIdentifier $right Right side of join condition
		 */
		private function checkTemporaryRangeRequired(
			AstRangeDatabase $range,
			bool             $isMainRange,
			AstIdentifier    $left,
			AstIdentifier    $right
		): void {
			// Identify which side contains the temporary range
			$joinedRange = $isMainRange ? $right->getRange() : $left->getRange();
			
			// Only process if it's actually a temporary range with a subquery
			if (!($joinedRange instanceof AstRangeDatabase) || !$joinedRange->containsQuery()) {
				return;
			}
			
			try {
				// Reuse existing visitor to check field nullability
				$visitor = new ContainsNonNullableFieldForRangeTemporary(
					$joinedRange->getName(),
					$joinedRange->getQuery(),
					$this->entityStore
				);
				
				// Create a test identifier to check the specific field
				// The visitor will analyze if this field reference is non-nullable
				$testIdentifier = $isMainRange ? $right : $left;
				$testIdentifier->accept($visitor);
				
				// If visitor didn't throw, field is nullable - keep as LEFT JOIN
			} catch (\Exception $e) {
				// Visitor throws exception when non-nullable field is found
				// Non-nullable field in join = can safely convert to INNER JOIN
				$range->setRequired();
			}
		}
		
		/**
		 * Quick check if an annotation array contains any RequiredRelation annotations.
		 *
		 * This is a performance optimization to avoid expensive annotation analysis
		 * when we know there are no relevant annotations in a group.
		 *
		 * Why This Optimization Matters:
		 * - Entity annotations can contain dozens of different annotation types
		 * - Most annotation groups won't contain RequiredRelation annotations
		 * - Early filtering prevents unnecessary detailed analysis
		 * - Reduces overall optimization time for complex entities
		 *
		 * @param array $annotations Array of annotations to check
		 * @return bool True if any RequiredRelation annotations are found
		 */
		private function containsRequiredRelationAnnotation(array $annotations): bool {
			foreach ($annotations as $annotation) {
				if ($annotation instanceof RequiredRelation) {
					return true;
				}
			}
			return false;
		}
		
		/**
		 * Checks if an annotation matches the current join relationship for required relations.
		 *
		 * This method implements the core matching logic for determining when a LEFT JOIN
		 * should be converted to an INNER JOIN based on ORM annotations.
		 *
		 * Matching Criteria (ALL must be true):
		 * 1. Annotation type: ManyToOne or OneToOne relationship
		 *    - These are the only relationship types that can be "required"
		 *    - OneToMany and ManyToMany relationships are collections (always optional)
		 *
		 * 2. Target entity match: annotation.targetEntity === relatedEntityName
		 *    - Ensures we're talking about the same destination entity
		 *    - Prevents cross-wiring of different relationships
		 *
		 * 3. Relation column match: annotation.relationColumn === ownPropertyName
		 *    - The foreign key field must match the JOIN condition
		 *    - Ensures semantic consistency between ORM and SQL
		 *
		 * 4. Inverse property match: annotation.inversedBy === relatedPropertyName
		 *    - The back-reference field must match the other side of JOIN
		 *    - Provides bidirectional relationship verification
		 *
		 * Example Scenario:
		 * ```
		 * class User {
		 * @ManyToOne(targetEntity="Department", relationColumn="department_id", inversedBy="users")
		 * @RequiredRelation
		 *   private $department;
		 * }
		 *
		 * SQL: SELECT * FROM users u LEFT JOIN departments d ON u.department_id = d.id
		 * ```
		 *
		 * Matching Process:
		 * - relatedEntityName = "Department" ✓
		 * - ownPropertyName = "department_id" ✓
		 * - relatedPropertyName = "id" → inversedBy = "users" ✗ (mismatch)
		 *
		 * This would NOT match because the inverse property doesn't align.
		 *
		 * @param mixed $annotation The annotation to check
		 * @param string $relatedEntityName Entity being joined to
		 * @param string $ownPropertyName Property on the owning side of the relationship
		 * @param string $relatedPropertyName Property on the related side of the relationship
		 * @return bool True if this annotation requires the relation
		 */
		private function isMatchingRequiredRelation(
			mixed  $annotation,
			string $relatedEntityName,
			string $ownPropertyName,
			string $relatedPropertyName
		): bool {
			// Check all matching criteria in sequence
			return ($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
				$annotation->getTargetEntity() === $relatedEntityName &&
				$annotation->getRelationColumn() === $ownPropertyName &&
				$annotation->getInversedBy() === $relatedPropertyName;
		}
	}