<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	use Quellabs\ObjectQuel\Execution\Visitors\VisitorRangeNotInAny;
	
	class QueryOptimizer {
		
		private EntityStore $entityStore;
		
		/**
		 * QueryBuilder constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
		}
		
		/**
		 * Optimize the query
		 * @param AstRetrieve $ast
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			$this->setOnlyRangeToRequired($ast);
			$this->setRangesRequiredThroughAnnotations($ast);
			$this->setRangesRequiredThroughWhereClause($ast);
			$this->setRangesNotRequiredThroughNullChecks($ast);
			$this->processExistsOperators($ast);
			$this->optimizeAnyFunctions($ast);
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
		 * Sets ranges as required when they're used in WHERE clause conditions.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesRequiredThroughWhereClause(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to analyze
			// Without conditions, there are no references to examine
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Examine each table/range involved in the query
			foreach ($ast->getRanges() as $range) {
				try {
					// Only process ranges that are currently marked as NOT required (optional)
					// Required ranges don't need this check since they're already marked as needed
					if ($range->isRequired()) {
						continue;
					}
					
					// Create a visitor to search for any references to this specific range/table
					// in the WHERE clause conditions
					$visitor = new ContainsRange($range->getName());
					
					// Traverse the condition tree looking for references to this range
					// The visitor will detect patterns like "table.field = value" or "table.id IN (...)"
					$ast->getConditions()->accept($visitor);
					
					// If we reach this point, no references to this range were found in WHERE clause
					// The range remains optional (no action needed)
					
				} catch (\Exception $e) {
					// Exception indicates that references to this range were found in WHERE conditions
					// When a table/range is referenced in WHERE clause, it must be required
					// because filtering on a field requires the record to exist (INNER JOIN behavior)
					// Mark this range as required, converting it from LEFT JOIN to INNER JOIN
					$range->setRequired();
				}
			}
		}
		
		/**
		 * Sets ranges as not required when NULL checks are used in WHERE clause.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesNotRequiredThroughNullChecks(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to analyze
			// Without conditions, there can't be any NULL checks to process
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Examine each table/range involved in the query
			foreach ($ast->getRanges() as $range) {
				// Only process ranges that are currently marked as required
				// Non-required ranges don't need this optimization check
				if ($range->isRequired()) {
					try {
						// Create a specialized visitor to check if there are IS NULL conditions
						// for fields belonging to this specific range/table
						$visitor = new ContainsCheckIsNullForRange($range->getName());
						
						// Traverse the condition tree to look for NULL checks on this range
						// The visitor will examine all conditions and detect patterns like "table.field IS NULL"
						$ast->getConditions()->accept($visitor);
						
						// If we reach this point, no NULL checks were found for this range
						// The range remains required (no action needed)
						
					} catch (\Exception $e) {
						// Exception indicates that IS NULL checks were found for this range
						// When a field is checked for NULL, the associated table/range becomes optional
						// because NULL checks imply the record might not exist (LEFT JOIN scenario)
						// Mark this range as not required, converting it from INNER JOIN to LEFT JOIN
						$range->setRequired(false);
					}
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
		private function isRangeOnlyUsedInAny(AstRetrieve $retrieve, AstRange $range): bool {
			try {
				// Create a visitor that will throw an exception if it finds the range
				// being used outside of ANY() functions
				$visitor = new VisitorRangeNotInAny($range);
				
				// Check all SELECT values/expressions for non-ANY usage of this range
				// If the range is referenced directly in SELECT clause (not in ANY), visitor throws exception
				foreach ($retrieve->getValues() as $value) {
					$value->accept($visitor);
				}
				
				// Check WHERE conditions for non-ANY usage of this range
				// If the range is used in filters outside of ANY functions, visitor throws exception
				if ($retrieve->getConditions()) {
					$retrieve->getConditions()->accept($visitor);
				}
				
				// If we reach this point, no non-ANY usage was found
				// The range is only used within ANY() functions
				return true;
			} catch (\Exception $e) {
				// Exception indicates the range is used outside of ANY functions
				// Therefore it cannot be optimized away from JOIN operations
				return false;
			}
		}
		
		/**
		 * Optimizes ranges that are only used in ANY() functions by excluding them from JOIN operations.
		 * When a range is only referenced within ANY() functions, it doesn't need to be joined
		 * as a regular table since ANY() can be handled more efficiently as a subquery.
		 * @param AstRetrieve $ast The AST retrieve object to optimize
		 * @return void
		 */
		private function optimizeAnyFunctions(AstRetrieve $ast): void {
			// Examine each range/table in the query for ANY-only optimization opportunities
			foreach ($ast->getRanges() as $range) {
				// Check if this range is exclusively used within ANY() functions
				// and not referenced in regular SELECT fields or WHERE conditions
				if ($this->isRangeOnlyUsedInAny($ast, $range)) {
					// Exclude this range from being included as a JOIN
					// The ANY() function can handle this more efficiently as a subquery
					// rather than requiring a full table join
					$range->setIncludeAsJoin(false);
				}
			}
		}
	}