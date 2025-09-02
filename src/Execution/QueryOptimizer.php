<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNonNullableFieldForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GatherReferenceJoinValues;
	
	class QueryOptimizer {
		
		/**
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * @var array|string[]
		 */
		private array $aggregateTypes;
		
		/**
		 * @var AstNodeReplacer
		 */
		private AstNodeReplacer $astNodeReplacer;
		
		/**
		 * QueryBuilder constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->astNodeReplacer = new AstNodeReplacer();
			
			// Define all aggregate function AST node types that are prohibited in WHERE clauses
			// This covers standard SQL aggregate functions and their variations
			$this->aggregateTypes = [
				AstCount::class,    // COUNT() function
				AstCountU::class,   // COUNT(DISTINCT) function
				AstAvg::class,      // AVG() function
				AstAvgU::class,     // AVG(DISTINCT) function
				AstSum::class,      // SUM() function
				AstSumU::class,     // SUM(DISTINCT) function,
				AstMin::class,      // MIN() function
				AstMax::class,      // MAX() function
				AstAny::class       // ANY() function
			];
		}
		
		/**
		 * Optimize the query
		 * @param AstRetrieve $ast
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			$this->setOnlyRangeToRequired($ast);
			$this->setRangesRequiredThroughAnnotations($ast);
			$this->eliminateRedundantSelfJoins($ast);
			$this->optimizeJoinTypesFromWhereClause($ast);
			$this->processExistsOperators($ast);
			$this->removeUnusedLeftJoinRanges($ast);
			$this->optimizeAggregateFunctions($ast);
			$this->addReferencedValuesToQuery($ast);
		}
		
		/**
		 * Sets the single range in an AST retrieve operation as required.
		 * Only applies the required flag when exactly one range exists.
		 * @param AstRetrieve $ast The AST retrieve object containing ranges
		 * @return void
		 */
		private function setOnlyRangeToRequired(AstRetrieve $ast): void {
			// Get all ranges from the AST retrieve object
			$ranges = $ast->getRanges();
			
			// Only set as required if there's exactly one range
			// This prevents ambiguity when multiple ranges exist
			if (count($ranges) === 1) {
				$ranges[0]->setRequired();
			}
		}
		
		/**
		 * Sets ranges as required based on RequiredRelation annotations.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesRequiredThroughAnnotations(AstRetrieve $ast): void {
			// Get the main/primary table/range for this query
			// This serves as the reference point for checking relationship annotations
			$mainRange = $this->getMainRange($ast);
			
			// Early exit if we can't determine the main range
			// Without a main range, we can't properly evaluate relationship annotations
			if ($mainRange === null) {
				return;
			}
			
			// Examine each table/range in the query to check for annotation-based requirements
			foreach ($ast->getRanges() as $range) {
				// Only process ranges that meet the criteria for annotation-based requirement checking
				// This filters out ranges that are already required or don't have join properties
				if (!$this->shouldSetRangeRequired($range)) {
					continue;
				}
				
				// Get the join property that defines how this range connects to other tables
				// This contains the left and right identifiers that form the join condition
				$joinProperty = $range->getJoinProperty();
				$left = $this->getBinaryLeft($joinProperty);
				$right = $this->getBinaryRight($joinProperty);
				
				// Normalize the join relationship so that $left always represents the current range
				// Join conditions can be written as "A.id = B.id" or "B.id = A.id"
				// We need consistent ordering to properly check annotations
				// @phpstan-ignore-next-line method.notFound
				if ($right->getEntityName() === $range->getEntityName()) {
					// Swap left and right if right side matches current range
					[$left, $right] = [$right, $left];
				}
				
				// Verify that after normalization, left side actually belongs to current range
				// This is a safety check to ensure our relationship mapping is correct
				// @phpstan-ignore-next-line method.notFound
				if ($left->getEntityName() === $range->getEntityName()) {
					// Check entity annotations to determine if this relationship should be required
					// This examines @RequiredRelation or similar annotations that force INNER JOINs
					$this->checkAndSetRangeRequired($mainRange, $range, $left, $right);
				}
			}
		}
		
		/**
		 * Optimizes JOIN types based on WHERE clause analysis.
		 * Converts LEFT JOINs to INNER JOINs when safe, and vice versa.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function optimizeJoinTypesFromWhereClause(AstRetrieve $ast): void {
			if ($ast->getConditions() === null) {
				return;
			}
			
			foreach ($ast->getRanges() as $range) {
				$this->analyzeRangeForJoinOptimization($ast, $range);
			}
		}
		
		/**
		 * Analyzes a specific range to determine the optimal JOIN type based on WHERE clause conditions.
		 *
		 * This method implements a sophisticated JOIN optimization strategy:
		 *
		 * 1. **NULL Check Priority**: If the range has explicit NULL checks (IS NULL conditions),
		 *    it must remain as LEFT JOIN regardless of other factors, since NULL checks
		 *    specifically require the ability to match records that don't exist.
		 *
		 * 2. **Field Reference Analysis**: When a range is referenced in WHERE conditions
		 *    without NULL checks, we analyze whether those references are safe to convert
		 *    to INNER JOIN by examining column nullability.
		 *
		 * 3. **Nullability-Based Conversion**: Only convert LEFT JOIN to INNER JOIN when
		 *    the referenced fields are non-nullable, ensuring the optimization doesn't
		 *    change query semantics or filter out valid results.
		 *
		 * The optimization logic follows this decision tree:
		 * - Has NULL checks? → Keep as LEFT JOIN (exit early)
		 * - Has field references + no NULL checks + non-nullable fields? → Convert to INNER JOIN
		 * - Otherwise → Leave unchanged
		 *
		 * This ensures we only perform safe optimizations that maintain query correctness
		 * while potentially improving performance by reducing the result set size.
		 *
		 * @param AstRetrieve $ast The query AST containing WHERE conditions to analyze
		 * @param AstRange $range The specific range/table to optimize JOIN type for
		 * @return void Modifies the range's required flag in-place
		 */
		private function analyzeRangeForJoinOptimization(AstRetrieve $ast, AstRange $range): void {
			// Check for NULL checks first (these keep ranges as LEFT JOIN)
			$hasNullChecks = $this->rangeHasNullChecks($ast, $range);
			
			// Check for field references
			$hasFieldReferences = $this->conditionsListHasFieldReferences($ast, $range);
			
			// If currently required but has NULL checks, make it optional
			if ($range->isRequired() && $hasNullChecks) {
				$range->setRequired(false);
				return;
			}
			
			// If currently optional but has field references, check nullability
			if (!$range->isRequired() && $hasFieldReferences && !$hasNullChecks) {
				$hasNonNullableReferences = $this->conditionListHasNonNullableReferences($ast, $range);
				
				if ($hasNonNullableReferences) {
					$range->setRequired();
				}
			}
		}
		
		// ========== HELPER METHODS ==========
		
		/**
		 * Returns the main range (first range without join property).
		 * @param AstRetrieve $ast
		 * @return AstRangeDatabase|null
		 */
		private function getMainRange(AstRetrieve $ast): ?AstRangeDatabase {
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeDatabase && $range->getJoinProperty() === null) {
					return $range;
				}
			}
			
			return null;
		}
		
		/**
		 * Checks if a range should be set as required based on its join property structure.
		 * @param $range
		 * @return bool
		 */
		private function shouldSetRangeRequired($range): bool {
			$joinProperty = $range->getJoinProperty();
			
			return $joinProperty instanceof AstExpression &&
				$joinProperty->getLeft() instanceof AstIdentifier &&
				$joinProperty->getRight() instanceof AstIdentifier;
		}
		
		/**
		 * Checks annotations and sets range as required if needed.
		 * @param AstRangeDatabase $mainRange
		 * @param AstRangeDatabase $range
		 * @param AstIdentifier $left
		 * @param AstIdentifier $right
		 * @return void
		 */
		private function checkAndSetRangeRequired(AstRangeDatabase $mainRange, AstRangeDatabase $range, AstIdentifier $left, AstIdentifier $right): void {
			// Determine which identifier belongs to the main range to establish perspective
			$isMainRange = $right->getRange() === $mainRange;
			
			// Extract property and entity names from the perspective of the "own" side
			// If right is main range, then right is "own" and left is "related", otherwise vice versa
			$ownPropertyName = $isMainRange ? $right->getName() : $left->getName();
			$ownEntityName = $isMainRange ? $right->getEntityName() : $left->getEntityName();
			
			// Extract property and entity names from the perspective of the "related" side
			$relatedPropertyName = $isMainRange ? $left->getName() : $right->getName();
			$relatedEntityName = $isMainRange ? $left->getEntityName() : $right->getEntityName();
			
			// Retrieve all annotations for the "own" entity from the entity store
			$entityAnnotations = $this->entityStore->getAnnotations($ownEntityName);
			
			// Iterate through each set of annotations for the entity
			foreach ($entityAnnotations as $annotations) {
				// Quick check: skip if this annotation set doesn't contain required relation annotations
				if (!$this->containsRequiredRelationAnnotation($annotations->toArray())) {
					continue;
				}
				
				// Examine each individual annotation in the set
				foreach ($annotations as $annotation) {
					// Check if this annotation defines a required relationship that matches our current relationship
					// (comparing entity names and property names on both sides)
					if ($this->isMatchingRequiredRelation($annotation, $relatedEntityName, $ownPropertyName, $relatedPropertyName)) {
						$range->setRequired();
						return;
					}
				}
			}
		}
		
		/**
		 * Checks if annotations contain RequiredRelation.
		 * @param array $annotations
		 * @return bool
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
		 * Checks if annotation matches the required relation criteria.
		 * @param $annotation
		 * @param string $relatedEntityName
		 * @param string $ownPropertyName
		 * @param string $relatedPropertyName
		 * @return bool
		 */
		private function isMatchingRequiredRelation($annotation, string $relatedEntityName, string $ownPropertyName, string $relatedPropertyName): bool {
			return ($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
				$annotation->getTargetEntity() === $relatedEntityName &&
				$annotation->getRelationColumn() === $ownPropertyName &&
				$annotation->getInversedBy() === $relatedPropertyName;
		}
		
		/**
		 * Processes EXISTS operators by removing them from conditions and setting ranges as required.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function processExistsOperators(AstRetrieve $ast): void {
			// Get the WHERE conditions from the query
			$conditions = $ast->getConditions();
			
			// Early exit if there are no conditions to process
			// Without conditions, there can't be any EXISTS operators to handle
			if ($conditions === null) {
				return;
			}
			
			// Extract all EXISTS operators from the condition tree
			// This method traverses the AST to find EXISTS clauses and removes them from their current location
			// Returns a list of the extracted EXISTS operators for further processing
			$existsList = $this->extractExistsOperators($ast, $conditions);
			
			// Process each extracted EXISTS operator
			foreach ($existsList as $exists) {
				// Convert the EXISTS operator into a required range/join
				// Instead of using EXISTS in the WHERE clause, this marks the related table/range as required
				// This is an optimization that can convert EXISTS subqueries into more efficient JOINs
				$this->setRangeRequiredForExists($ast, $exists);
			}
		}
		
		/**
		 * Sets the range as required for an EXISTS operation.
		 * @param AstRetrieve $ast
		 * @param AstExists $exists
		 * @return void
		 */
		private function setRangeRequiredForExists(AstRetrieve $ast, AstExists $exists): void {
			$existsRange = $exists->getIdentifier()->getRange();
			
			foreach ($ast->getRanges() as $range) {
				if ($range->getName() === $existsRange->getName()) {
					$range->setRequired();
					break;
				}
			}
		}
		
		/**
		 * Extracts EXISTS operators from conditions and handles different scenarios.
		 * @param AstRetrieve $ast
		 * @param AstInterface $conditions
		 * @return array
		 */
		private function extractExistsOperators(AstRetrieve $ast, AstInterface $conditions): array {
			if ($conditions instanceof AstExists) {
				$ast->setConditions(null);
				return [$conditions];
			}
			
			if ($conditions instanceof AstBinaryOperator) {
				$existsList = [];
				$this->extractExistsFromBinaryOperator($ast, $conditions, $existsList);
				return $existsList;
			}
			
			return [];
		}
		
		/**
		 * Recursively extracts EXISTS operators from binary operations.
		 * @param AstInterface|null $parent
		 * @param AstInterface $item
		 * @param array $list
		 * @param bool $parentLeft
		 * @return void
		 */
		private function extractExistsFromBinaryOperator(?AstInterface $parent, AstInterface $item, array &$list, bool $parentLeft = false): void {
			if (!$this->isBinaryOperationNode($item)) {
				return;
			}
			
			// Handle left/right
			$left = $this->getBinaryLeft($item);
			$right = $this->getBinaryRight($item);
			
			// Process branches recursively
			if ($left instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $left, $list, true);
			}
			
			if ($right instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $right, $list, false);
			}
			
			// Handle special case: exists AND/OR exists as only condition
			$left = $this->getBinaryLeft($item);
			$right = $this->getBinaryRight($item);
			
			if ($parent instanceof AstRetrieve && $left instanceof AstExists && $right instanceof AstExists) {
				$list[] = $left;
				$list[] = $right;
				$parent->setConditions(null);
				return;
			}
			
			// Handle EXISTS in left branch
			if ($left instanceof AstExists) {
				$list[] = $left;
				$this->setChildInParent($parent, $right, $parentLeft);
			}
			
			// Handle EXISTS in right branch
			if ($right instanceof AstExists) {
				$list[] = $right;
				$this->setChildInParent($parent, $left, $parentLeft);
			}
		}
		
		/**
		 * Sets the appropriate child relationship between parent and item nodes.
		 * @param AstInterface|null $parent
		 * @param AstInterface $item
		 * @param bool $parentLeft
		 * @return void
		 */
		private function setChildInParent(?AstInterface $parent, AstInterface $item, bool $parentLeft): void {
			// Handle special case for AstRetrieve nodes - they use conditions instead of left/right children
			if ($parent instanceof AstRetrieve) {
				$parent->setConditions($item);
				return;
			}
			
			// Check if the parent node supports binary operations (has left/right children)
			// If not, we can't process it further
			if (!$this->isBinaryOperationNode($parent)) {
				return;
			}
			
			// For binary operations, determine which side (left or right) to assign the item
			if ($parentLeft) {
				// Assign item as the left operand of the binary operation
				$this->setBinaryLeft($parent, $item);
			} else {
				// Assign item as the right operand of the binary operation
				$this->setBinaryRight($parent, $item);
			}
		}
		
		/**
		 * Checks if the item can be processed for binary operations.
		 * @param AstInterface|null $item
		 * @return bool
		 */
		private function isBinaryOperationNode(?AstInterface $item): bool {
			if ($item === null) {
				return false;
			}
			
			return $item instanceof AstTerm ||
				$item instanceof AstBinaryOperator ||
				$item instanceof AstExpression ||
				$item instanceof AstFactor;
		}
		
		/**
		 * Gets the left child from a binary operation node.
		 * @param AstInterface $item
		 * @return AstInterface
		 */
		private function getBinaryLeft(AstInterface $item): AstInterface {
			if (
				!$item instanceof AstTerm &&
				!$item instanceof AstBinaryOperator &&
				!$item instanceof AstExpression &&
				!$item instanceof AstFactor
			) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			return $item->getLeft();
		}
		
		/**
		 * Gets the right child from a binary operation node.
		 * @param AstInterface $item
		 * @return AstInterface
		 */
		private function getBinaryRight(AstInterface $item): AstInterface {
			if (
				!$item instanceof AstTerm &&
				!$item instanceof AstBinaryOperator &&
				!$item instanceof AstExpression &&
				!$item instanceof AstFactor
			) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			return $item->getRight();
		}
		
		/**
		 * Sets the left child of a binary operation node.
		 * @param AstInterface $item
		 * @param AstInterface $left
		 * @return void
		 */
		private function setBinaryLeft(AstInterface $item, AstInterface $left): void {
			if (
				!$item instanceof AstTerm &&
				!$item instanceof AstBinaryOperator &&
				!$item instanceof AstExpression &&
				!$item instanceof AstFactor
			) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			$item->setLeft($left);
		}
		
		/**
		 * Sets the right child of a binary operation node.
		 * @param AstInterface $item
		 * @param AstInterface $right
		 * @return void
		 */
		private function setBinaryRight(AstInterface $item, AstInterface $right): void {
			if (
				!$item instanceof AstTerm &&
				!$item instanceof AstBinaryOperator &&
				!$item instanceof AstExpression &&
				!$item instanceof AstFactor
			) {
				throw new \InvalidArgumentException('Item does not support binary operations');
			}
			
			$item->setRight($right);
		}
		
		/**
		 * Checks if a range is only used within ANY() functions and not referenced elsewhere.
		 * This is used to determine if a range can be optimized out of JOIN operations.
		 * @param AstRetrieve $retrieve The retrieve operation to analyze
		 * @param AstRange $range The range/table to check for ANY-only usage
		 * @return bool True if range is only used in ANY functions, false otherwise
		 */
		private function isRangeOnlyUsedInAggregates(AstRetrieve $retrieve, AstRange $range): bool {
			// Get list of all identifiers
			$allIdentifiers = $retrieve->getAllIdentifiers($range);
			
			// Range not used at all
			if (empty($allIdentifiers)) {
				return false;
			}
			
			// Check if ALL identifiers are inside aggregate nodes
			foreach ($allIdentifiers as $identifier) {
				// Fetch the parent aggregate (if any)
				$parentAggregate = $identifier->getParentAggregate();
				
				// Found usage outside aggregate
				if ($parentAggregate === null) {
					return false;
				}
			}
			
			// All usages are inside aggregates
			return true;
		}
		
		private function isAggregateNode(AstInterface $item): bool {
			return
				$item instanceof AstCount ||
				$item instanceof AstCountU ||
				$item instanceof AstAvg ||
				$item instanceof AstAvgU ||
				$item instanceof AstSum ||
				$item instanceof AstSumU ||
				$item instanceof AstMin ||
				$item instanceof AstMax ||
				$item instanceof AstAny;
		}
		
		private function valuesOnlyAggregates(AstRetrieve $ast): bool {
			foreach ($ast->getValues() as $value) {
				if (!$this->isAggregateNode($value->getExpression())) {
					return false;
				}
			}
			
			return true;
		}
	
		/**
		 * Optimizes aggregate functions in AST queries by choosing the most efficient execution strategy.
		 *
		 * This method handles two main optimization scenarios:
		 * 1. Single-range queries: Direct optimization within the main query
		 * 2. Multi-range queries: Decides between subqueries vs CASE WHEN transformations
		 *
		 * @param AstRetrieve $ast The AST retrieve object to optimize
		 * @return void
		 */
		private function optimizeAggregateFunctions(AstRetrieve $ast): void {
			// Handle the simple case: single range queries
			if ($ast->isSingleRangeQuery(true)) {
				$this->optimizeSingleRangeAggregates($ast);
				return;
			}
			
			// Handle complex case: multi-range queries
			$this->optimizeMultiRangeAggregates($ast);
		}
		
		/**
		 * Optimizes aggregate functions in single-range queries.
		 *
		 * For single-range queries, we can apply more aggressive optimizations:
		 * - Move aggregate conditions to the main WHERE clause when possible
		 * - Transform remaining conditional aggregates to CASE WHEN expressions
		 *
		 * @param AstRetrieve $ast The single-range AST to optimize
		 * @return void
		 */
		private function optimizeSingleRangeAggregates(AstRetrieve $ast): void {
			// Special optimization: if we're selecting only one aggregate with a condition,
			// and there's no existing WHERE clause, move the aggregate condition up
			$canPromoteCondition = (
				count($ast->getValues()) === 1 &&
				$this->isAggregateNode($ast->getValues()[0]->getExpression()) &&
				$ast->getValues()[0]->getExpression()->getConditions() !== null &&
				$ast->getConditions() === null
			);
			
			if ($canPromoteCondition) {
				// Move: SELECT SUM(value WHERE condition) → SELECT SUM(value) WHERE condition
				$aggregateCondition = $ast->getValues()[0]->getExpression()->getConditions();
				$ast->setConditions($aggregateCondition);
				$ast->getValues()[0]->getExpression()->setConditions(null);
			}
			
			// Transform any remaining conditional aggregates to CASE WHEN
			$this->transformAggregatesToCaseWhen($ast);
		}
		
		/**
		 * Optimizes aggregate functions in multi-range queries.
		 * @param AstRetrieve $ast The multi-range AST to optimize
		 * @return void
		 */
		private function optimizeMultiRangeAggregates(AstRetrieve $ast): void {
			// Find all aggregate nodes that need optimization
			$valueAggregates = $this->findAggregatesForValues($ast->getValues());
			$conditionAggregates = $this->findAggregatesForConditions($ast->getConditions());
			$allAggregates = array_merge($valueAggregates, $conditionAggregates);
			
			// Process each aggregate and choose the best optimization strategy
			foreach ($allAggregates as $aggregateNode) {
				if (!$this->shouldWrapAggregation($ast, $aggregateNode)) {
					continue;
				}
				
				if ($this->isSubqueryMoreEfficient($ast, $aggregateNode)) {
					$this->convertAggregateToSubquery($ast, $aggregateNode);
				} else {
					$this->transformAggregateToCase($aggregateNode);
				}
			}
		}
		
		/**
		 * Converts an aggregate node to an appropriate subquery type.
		 *
		 * The subquery type depends on the context where the aggregate appears:
		 * - Scalar subquery: For non-ANY aggregates (e.g., SUM, COUNT)
		 * - EXISTS subquery: For ANY aggregates in WHERE/HAVING clauses
		 * - CASE WHEN subquery: For ANY aggregates in SELECT clauses
		 *
		 * @param AstRetrieve $ast The main query AST
		 * @param AstInterface $aggregateNode The aggregate to convert
		 * @return void
		 */
		private function convertAggregateToSubquery(AstRetrieve $ast, AstInterface $aggregateNode): void {
			$parentNode = $aggregateNode->getParent();
			
			// Regular aggregates (SUM, COUNT, etc.) become scalar subqueries
			// Example: SUM(table.value WHERE condition) → (SELECT SUM(table.value) WHERE condition)
			if (!$aggregateNode instanceof AstAny) {
				$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_SCALAR);
				$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
				return;
			}
			
			// ANY aggregates in WHERE/HAVING become EXISTS subqueries
			// Example: WHERE ANY(table.value > 10) → WHERE EXISTS(SELECT 1 WHERE table.value > 10)
			if ($aggregateNode->isAncestorOf($ast->getConditions())) {
				$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_EXISTS);
				$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
				return;
			}
			
			// ANY aggregates in SELECT become CASE WHEN EXISTS subqueries
			// Example: SELECT ANY(table.value > 10) → SELECT CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
			$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_CASE_WHEN);
			$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
		}
		
		/**
		 * Determines if a subquery approach would be more efficient than CASE WHEN.
		 * @param AstRetrieve $ast The main query AST
		 * @param AstInterface $aggregation The aggregate node to evaluate
		 * @return bool True if subquery is more efficient, false for CASE WHEN
		 */
		private function isSubqueryMoreEfficient(AstRetrieve $ast, AstInterface $aggregation): bool {
			// TODO: Implement cost-based analysis
			// For now, conservatively prefer CASE WHEN transformations
			return false;
		}
		
		/**
		 * Transforms a conditional aggregate to use CASE WHEN instead of WHERE clauses.
		 * Before: SUM(expression WHERE condition)
		 * After:  SUM(CASE WHEN condition THEN expression ELSE NULL END)
		 * @param AstInterface $aggregation The aggregate node to transform
		 * @return void
		 */
		private function transformAggregateToCase(AstInterface $aggregation): void {
			// Only process aggregates that have conditions
			$condition = $aggregation->getConditions();
			
			if ($condition === null) {
				return;
			}
			
			// Get the current expression being aggregated
			$expression = $aggregation->getIdentifier();
			
			// Create the CASE WHEN structure: CASE WHEN condition THEN expression END
			// The implicit ELSE NULL ensures that rows not meeting the condition
			// don't contribute to the aggregate (which is the desired behavior)
			$caseWhenExpression = new AstCase($condition, $expression);
			
			// Update the aggregate: replace expression and remove the separate condition
			$aggregation->setIdentifier($caseWhenExpression);
			$aggregation->setConditions(null);
		}
		
		private function transformAggregatesToCaseWhen(AstRetrieve $ast): void {
			$aggregationNodes = $this->findAggregatesForValues($ast->getValues());
			
			foreach ($aggregationNodes as $aggregate) {
				if ($aggregate->getConditions() !== null) {
					// Transform: SUM(expr WHERE condition) → SUM(CASE WHEN condition THEN expr END)
					$condition = $aggregate->getConditions();
					$expression = $aggregate->getExpression();
					
					// Create CASE WHEN structure (you'll need to implement AstCase class)
					$caseWhen = new AstCase($condition, $expression); // CASE WHEN condition THEN expr END
					
					// Replace the aggregate's expression with the CASE WHEN
					$aggregate->setExpression($caseWhen);
					$aggregate->setConditions(null);
				}
			}
		}
		
		/**
		 * Returns true if the aggregation should be wrapped inside a subquery node
		 * @param AstRetrieve $ast
		 * @param AstInterface $aggregate
		 * @return bool
		 */
		private function shouldWrapAggregation(AstRetrieve $ast, AstInterface $aggregate): bool {
			// Only wrap if it has conditions AND it's not a simple single-range case
			if ($aggregate->getConditions() === null) {
				return false;
			}
			
			// For single range queries, we can use CASE WHEN instead of subqueries
			if ($ast->isSingleRangeQuery(true)) {
				return false; // Don't wrap - we'll transform to CASE WHEN
			}
			
			return true; // Multi-range queries still need subqueries
		}
		
		/**
		 * Extracts all used ranges from the AST and returns them as a list.
		 * Traverses the entire AST structure using the visitor pattern to collect
		 * all range references that are used in expressions.
		 * @param AstInterface $ast The abstract syntax tree to analyze
		 * @return array List of range nodes found in the AST
		 */
		private function extractRanges(AstInterface $ast): array {
			$visitor = new CollectRanges();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Finds all aggregation function nodes within the provided value expressions.
		 * Scans through an array of AST nodes to identify aggregate functions like
		 * SUM, COUNT, AVG, etc. that will need special handling during query processing.
		 * @param array $values Array of AST nodes representing value expressions to analyze
		 * @return array Collection of aggregate function nodes found across all values
		 */
		private function findAggregatesForValues(array $values): array {
			// Create the visitor to collect aggregate nodes
			$visitor = new CollectNodes($this->aggregateTypes);
			
			// Visit each value expression to collect aggregate functions
			foreach ($values as $value) {
				$value->accept($visitor);
			}
			
			// Return the gathered list
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Searches for aggregation functions within conditional expressions (WHERE clauses).
		 * Returns empty array if no conditions are provided. Otherwise traverses the
		 * condition AST to find any aggregate functions that appear in filtering logic.
		 * @param AstInterface|null $conditions Optional condition AST to analyze, null if no WHERE clause
		 * @return array List of aggregate function nodes found in conditions, empty if no conditions
		 */
		private function findAggregatesForConditions(?AstInterface $conditions = null): array {
			// Early return for queries without WHERE clauses
			if ($conditions === null) {
				return [];
			}
			
			$visitor = new CollectNodes($this->aggregateTypes);
			$conditions->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Removes LEFT JOIN ranges that are not referenced anywhere in the query.
		 * This optimization eliminates unnecessary joins that don't contribute to the result.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function removeUnusedLeftJoinRanges(AstRetrieve $ast): void {
			$mainRange = $this->getMainRange($ast);
			
			foreach ($ast->getRanges() as $range) {
				// Skip main range - it's always needed
				if ($range === $mainRange) {
					continue;
				}
				
				// Skip required ranges (INNER JOINs) - they affect row count
				if ($range->isRequired()) {
					continue;
				}
				
				// Check if Range is unused
				if ($this->isRangeCompletelyUnused($ast, $range)) {
					$range->setIncludeAsJoin(false);
				}
			}
		}
		
		/**
		 * Checks if a range is completely unused in the entire query.
		 * A range is unused if it has no identifiers referencing it in any part of the AST.
		 * @param AstRetrieve $ast The query AST to check
		 * @param AstRange $range The range to check for usage
		 * @return bool True if range is completely unused, false otherwise
		 */
		private function isRangeCompletelyUnused(AstRetrieve $ast, AstRange $range): bool {
			// Get all identifiers that reference this range
			$allIdentifiers = $ast->getAllIdentifiers($range);
			
			// If no identifiers reference this range, it's completely unused
			return empty($allIdentifiers);
		}
		
		/**
		 * Adds referenced field values to the query's value list for join conditions.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addReferencedValuesToQuery(AstRetrieve $ast): void {
			// Early exit if there are no conditions to process
			// Without conditions, there won't be any referenced fields to gather
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Use a visitor pattern to traverse the AST and collect all identifiers
			// that are referenced in join conditions but not already in the SELECT list
			// GatherReferenceJoinValues is a specialized visitor that finds these missing references
			$visitor = $this->processWithVisitor($ast, GatherReferenceJoinValues::class);
			
			// Process each identifier that was found by the visitor
			foreach ($visitor->getIdentifiers() as $identifier) {
				// Create a deep copy of the identifier to avoid modifying the original
				// This ensures we don't accidentally affect other parts of the query tree
				$clonedIdentifier = $identifier->deepClone();
				
				// Wrap the cloned identifier in an alias using its complete name
				// This creates a proper SELECT field that can be referenced in joins
				$alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				
				// Mark this field as invisible in the final result set
				// These are technical fields needed for joins, not user-requested data
				// This prevents them from appearing in the output while still being available for JOIN conditions
				$alias->setVisibleInResult(false);
				
				// Add the aliased field to the query's value list (SELECT clause)
				// This ensures the field is available for join processing even though it's not visible to users
				$ast->addValue($alias);
			}
		}
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param string $visitorClass The visitor class name
		 * @param mixed ...$args Arguments to pass to visitor constructor
		 * @return object The visitor instance after processing
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): object {
			$visitor = new $visitorClass(...$args);
			$ast->accept($visitor);
			return $visitor;
		}
		
		/**
		 * This method uses a visitor pattern to traverse the AST conditions and detect
		 * if there are explicit IS NULL or IS NOT NULL checks for fields belonging to
		 * the specified range. The visitor throws an exception when a match is found
		 * (visitor pattern convention for early termination).
		 * @param AstRetrieve $ast The query AST containing conditions to check
		 * @param AstRange $range The range to check for NULL conditions
		 * @return bool True if NULL checks are found, false otherwise
		 */
		private function rangeHasNullChecks(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Create visitor to search for NULL checks on the specified range
				$visitor = new ContainsCheckIsNullForRange($range->getName());
				$ast->getConditions()->accept($visitor);
				
				// If visitor completes without throwing, no NULL checks were found
				return false;
			} catch (\Exception $e) {
				// Exception indicates NULL checks were found (visitor pattern convention)
				return true;
			}
		}
		
		/**
		 * This method determines if any fields from the specified range are referenced
		 * in the WHERE clause. Uses visitor pattern where the visitor throws an exception
		 * upon finding the first match, allowing for efficient early termination.
		 * @param AstRetrieve $ast The query AST containing conditions to check
		 * @param AstRange $range The range to check for field references
		 * @return bool True if field references are found, false otherwise
		 */
		private function conditionsListHasFieldReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Create visitor to search for any references to the specified range
				$visitor = new ContainsRange($range->getName());
				$ast->getConditions()->accept($visitor);
				
				// If visitor completes without throwing, no references were found
				return false;
			} catch (\Exception $e) {
				// Exception indicates references were found (visitor pattern convention)
				return true;
			}
		}
		
		/**
		 * This method identifies whether the WHERE conditions reference fields that are
		 * marked as non-nullable in the entity definition. Non-nullable field references
		 * can affect join elimination since they implicitly filter out NULL values.
		 * Uses visitor pattern with exception-based early termination.
		 * @param AstRetrieve $ast The query AST containing conditions to analyze
		 * @param AstRange $range The range to check for non-nullable field usage
		 * @return bool True if non-nullable fields are referenced, false otherwise
		 */
		private function conditionListHasNonNullableReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				// Create visitor to search for non-nullable field references
				$visitor = new ContainsNonNullableFieldForRange($range->getName(), $this->entityStore);
				$ast->getConditions()->accept($visitor);
				
				// If visitor completes without throwing, no non-nullable references found
				return false;
			} catch (\Exception $e) {
				// Exception indicates non-nullable references were found
				return true;
			}
		}
		
		/**
		 * Detects and eliminates redundant self-joins where ranges are functionally equivalent.
		 *
		 * This optimization identifies cases where multiple ranges reference the same entity
		 * with equivalent join conditions (e.g., c.id = d.id where both c and d reference
		 * the same table). Such redundant joins can be safely eliminated by merging the
		 * ranges and updating all references.
		 *
		 * Example transformation:
		 * FROM Customer c, Customer d WHERE c.id = d.id
		 * becomes:
		 * FROM Customer c
		 *
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		private function eliminateRedundantSelfJoins(AstRetrieve $ast): void {
			$ranges = $ast->getRanges();
			
			// Compare each pair of ranges to identify redundant self-joins
			foreach ($ranges as $i => $range1) {
				foreach ($ranges as $j => $range2) {
					// Skip comparing same range or avoid duplicate comparisons
					if ($i >= $j) continue;
					
					// Check if ranges are functionally equivalent (same entity + identity join)
					if ($this->areRangesFunctionallyEquivalent($range1, $range2)) {
						// Merge the redundant range into the first one
						$this->mergeRedundantRange($ast, $range1, $range2);
					}
				}
			}
		}
		
		/**
		 * Determines if two ranges are functionally equivalent for optimization purposes.
		 *
		 * Two ranges are considered equivalent if:
		 * 1. They reference the same entity type
		 * 2. They are joined by an identity condition (e.g., range1.id = range2.id)
		 *
		 * @param AstRange $range1 The first range to compare
		 * @param AstRange $range2 The second range to compare
		 * @return bool True if ranges are functionally equivalent, false otherwise
		 */
		private function areRangesFunctionallyEquivalent(AstRange $range1, AstRange $range2): bool {
			// Must reference the same entity type
			if ($range1->getEntityName() !== $range2->getEntityName()) {
				return false;
			}
			
			// Check if join condition creates equivalence (e.g., c.id = d.id)
			$joinProperty = $range2->getJoinProperty();
			if (!$this->isIdentityJoin($joinProperty, $range1, $range2)) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Checks if a join property represents an identity join between two ranges.
		 *
		 * An identity join is one where the same field from both ranges is compared
		 * for equality (e.g., range1.id = range2.id). This creates a functional
		 * equivalence that allows for range elimination.
		 *
		 * @param mixed $joinProperty The join expression to analyze
		 * @param AstRange $range1 The first range in the comparison
		 * @param AstRange $range2 The second range in the comparison
		 * @return bool True if this is an identity join, false otherwise
		 */
		private function isIdentityJoin($joinProperty, AstRange $range1, AstRange $range2): bool {
			// Join property must be a binary expression (e.g., A = B)
			if (!($joinProperty instanceof AstExpression)) {
				return false;
			}
			
			// Extract left and right sides of the binary operation
			$left = $this->getBinaryLeft($joinProperty);
			$right = $this->getBinaryRight($joinProperty);
			
			// Get field names from both sides of the comparison
			$leftFieldName = $left->getNext() ? $left->getNext()->getName() : null;
			$rightFieldName = $right->getNext() ? $right->getNext()->getName() : null;
			
			// Verify this is an identity join: range1.field = range2.field with same field name
			return ($left->getRange()->getName() === $range1->getName() &&
					$right->getRange()->getName() === $range2->getName() &&
					$leftFieldName === $rightFieldName) ||
				($left->getRange()->getName() === $range2->getName() &&
					$right->getRange()->getName() === $range1->getName() &&
					$leftFieldName === $rightFieldName);
		}
		
		/**
		 * Merges a redundant range into the kept range and removes it from the query.
		 * @param AstRetrieve $ast The query AST being optimized
		 * @param AstRange $keepRange The range to retain (target of merge)
		 * @param AstRange $removeRange The redundant range to eliminate
		 *
		 * @throws \RuntimeException If range replacement fails
		 */
		private function mergeRedundantRange(AstRetrieve $ast, AstRange $keepRange, AstRange $removeRange): void {
			// Replace all references to removeRange with keepRange throughout the AST
			$this->replaceRangeReferences($ast, $removeRange, $keepRange);
			
			// Mark the redundant range as excluded from join generation
			$removeRange->setIncludeAsJoin(false);
		}
		
		/**
		 * Replaces all references to oldRange with newRange throughout the AST.
		 * @param AstRetrieve $ast The query AST to update
		 * @param AstRange $oldRange The range being replaced
		 * @param AstRange $newRange The replacement range
		 * @throws \RuntimeException If reference replacement fails
		 */
		private function replaceRangeReferences(AstRetrieve $ast, AstRange $oldRange, AstRange $newRange): void {
			// Get all identifiers that reference the old range
			$identifiers = $ast->getAllIdentifiers($oldRange);
			
			// Update each identifier to reference the new range instead
			foreach ($identifiers as $identifier) {
				$identifier->setRange($newRange);
			}
			
			// Update join conditions that may reference the old range
			$this->updateJoinConditionsForRange($ast, $oldRange, $newRange);
		}
		
		/**
		 * This method ensures that all join expressions are updated when a range
		 * reference changes, maintaining the semantic correctness of the query
		 * after optimization.
		 * @param AstRetrieve $ast The query AST being updated
		 * @param AstRange $oldRange The range being replaced
		 * @param AstRange $newRange The replacement range
		 */
		private function updateJoinConditionsForRange(AstRetrieve $ast, AstRange $oldRange, AstRange $newRange): void {
			// Update join conditions for each range in the query
			foreach ($ast->getRanges() as $range) {
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty === null) {
					continue;
				}
				
				// Recursively update identifiers within join conditions
				$this->updateIdentifiersInExpression($joinProperty, $oldRange, $newRange);
			}
			
			// Also update main WHERE conditions if they exist
			if ($ast->getConditions() !== null) {
				$this->updateIdentifiersInExpression($ast->getConditions(), $oldRange, $newRange);
			}
		}
		
		/**
		 * This method traverses the AST expression tree and updates any identifier
		 * nodes that reference the old range to use the new range instead. It handles
		 * various expression types including binary operations, function calls, and
		 * aggregate expressions.
		 * @param AstInterface $expression The expression tree to update
		 * @param AstRange $oldRange The range being replaced
		 * @param AstRange $newRange The replacement range
		 */
		private function updateIdentifiersInExpression(AstInterface $expression, AstRange $oldRange, AstRange $newRange): void {
			// Handle direct identifier references
			if ($expression instanceof AstIdentifier && $expression->getRange() === $oldRange) {
				$expression->setRange($newRange);
				return;
			}
			
			// Handle binary operations recursively (AND, OR, =, <>, etc.)
			if ($this->isBinaryOperationNode($expression)) {
				$this->updateIdentifiersInExpression($this->getBinaryLeft($expression), $oldRange, $newRange);
				$this->updateIdentifiersInExpression($this->getBinaryRight($expression), $oldRange, $newRange);
				return;
			}
			
			// Handle other node types that might contain identifiers
			if (method_exists($expression, 'getIdentifier')) {
				$identifier = $expression->getIdentifier();
				if ($identifier instanceof AstIdentifier && $identifier->getRange() === $oldRange) {
					$identifier->setRange($newRange);
				}
			}
			
			// Handle aggregate functions that might have conditions (HAVING clauses)
			if (method_exists($expression, 'getConditions') && $expression->getConditions() !== null) {
				$this->updateIdentifiersInExpression($expression->getConditions(), $oldRange, $newRange);
			}
		}
	}