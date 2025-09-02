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
			$this->optimizeJoinTypesFromWhereClause($ast);
			$this->processExistsOperators($ast);
			$this->optimizeAggregateFunctions($ast);
			$this->markSubqueryRanges($ast);
			$this->removeUnusedLeftJoinRanges($ast);
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
		
		/**
		 * Optimizes ranges that are only used in ANY() functions by excluding them from JOIN operations.
		 * When a range is only referenced within ANY() functions, it doesn't need to be joined
		 * as a regular table since ANY() can be handled more efficiently as a subquery.
		 * @param AstRetrieve $ast The AST retrieve object to optimize
		 * @return void
		 */
		private function optimizeAggregateFunctions(AstRetrieve $ast): void {
			// Do not optimize single range queries
			if ($ast->isSingleRangeQuery()) {
				return;
			}
			
			// Fetch all aggregates to see if they should be wrapped into a subquery
			$aggregationNodesValues = $this->findAggregatesForValues($ast->getValues());
			$aggregationNodesConditions = $this->findAggregatesForConditions($ast->getConditions());
			$aggregationNodes = array_merge($aggregationNodesValues, $aggregationNodesConditions);
			
			foreach($aggregationNodes as $aggregation) {
				if ($this->shouldWrapAggregation($ast, $aggregation)) {
					$parent = $aggregation->getParent();
					
					if (!$aggregation instanceof AstAny) {
						// (SELECT SUM(...))
						$subquery = new AstSubquery($aggregation, AstSubquery::TYPE_SCALAR);
					} elseif ($aggregation->isAncestorOf($ast->getConditions())) {
						// WHERE/HAVING context - use EXISTS
						$subquery = new AstSubquery($aggregation, AstSubquery::TYPE_EXISTS);
					} else {
						// SELECT context - use CASE WHEN EXISTS
						$subquery = new AstSubquery($aggregation, AstSubquery::TYPE_CASE_WHEN);
					}
					
					$this->astNodeReplacer->replaceChild($parent, $aggregation, $subquery);
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
			// Step 1: Find ranges used by THIS aggregation
			$mainRange = $this->getMainRange($ast);
			$rangesInThisAggregation = $this->extractRanges($aggregate);
			
			// Step 2: Filter out main range to get only "additional" ranges
			$additionalRanges = array_filter($rangesInThisAggregation, function($range) use ($mainRange) {
				return $range !== $mainRange;
			});
			
			// Step 3: If no additional ranges, don't wrap (main range aggregations stay in main query)
			if (empty($additionalRanges)) {
				return false;
			}
			
			// Step 4: Check if all additional ranges are aggregation-only
			foreach ($additionalRanges as $range) {
				// This range is used outside aggregations
				if (!$this->isRangeOnlyUsedInAggregates($ast, $range)) {
					return false;
				}
			}
			
			// All additional ranges are aggregation-only
			return true;
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
			foreach($values as $value) {
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
		 * Marks ranges that have been moved into subqueries and should not appear
		 * in the main query's FROM/JOIN clauses.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function markSubqueryRanges(AstRetrieve $ast): void {
			$mainRange = $this->getMainRange($ast);
			
			foreach ($ast->getRanges() as $range) {
				// Skip main range
				if ($range === $mainRange) {
					continue;
				}
				
				// If range is only used in aggregates, mark it as subquery
				if ($this->isRangeOnlyUsedInAggregates($ast, $range)) {
					$range->setIncludeAsJoin(false);
				}
			}
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
		 * Checks if a range has explicit NULL checks in the WHERE clause conditions.
		 * @param AstRetrieve $ast The query AST containing conditions to check
		 * @param AstRange $range The range to check for NULL conditions
		 * @return bool True if NULL checks are found, false otherwise
		 */
		private function rangeHasNullChecks(AstRetrieve $ast, AstRange $range): bool {
			try {
				$visitor = new ContainsCheckIsNullForRange($range->getName());
				$ast->getConditions()->accept($visitor);
				return false;
			} catch (\Exception $e) {
				return true;
			}
		}
		
		/**
		 * Checks if a range has any field references in the WHERE clause conditions.
		 * @param AstRetrieve $ast The query AST containing conditions to check
		 * @param AstRange $range The range to check for field references
		 * @return bool True if field references are found, false otherwise
		 */
		private function conditionsListHasFieldReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				$visitor = new ContainsRange($range->getName());
				$ast->getConditions()->accept($visitor);
				return false;
			} catch (\Exception $e) {
				return true;
			}
		}
		
		/**
		 * Checks if a range has references to non-nullable fields in WHERE conditions.
		 * @param AstRetrieve $ast The query AST containing conditions to analyze
		 * @param AstRange $range The range to check for non-nullable field usage
		 * @return bool True if non-nullable fields are referenced, false otherwise
		 */
		private function conditionListHasNonNullableReferences(AstRetrieve $ast, AstRange $range): bool {
			try {
				$visitor = new ContainsNonNullableFieldForRange($range->getName(), $this->entityStore);
				$ast->getConditions()->accept($visitor);
				return false;
			} catch (\Exception $e) {
				return true;
			}
		}
	}