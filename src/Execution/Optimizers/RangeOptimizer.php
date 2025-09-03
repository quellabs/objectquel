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
	
	/**
	 * Handles range-specific optimizations including required annotations
	 * and unused LEFT JOIN removal.
	 *
	 * This optimizer performs two main functions:
	 * 1. Marks ranges as required to convert LEFT JOINs to INNER JOINs when appropriate
	 * 2. Removes unused LEFT JOIN ranges to reduce query complexity
	 */
	class RangeOptimizer {
		
		private EntityStore $entityStore;
		private BinaryOperationHelper $binaryHelper;
		
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->binaryHelper = new BinaryOperationHelper();
		}
		
		/**
		 * Main optimization entry point - applies all range optimizations
		 *
		 * @param AstRetrieve $ast The AST to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// First, mark single ranges as required (trivial case)
			$this->setOnlyRangeToRequired($ast);
			// Then check for annotation-based requirements
			$this->setRangesRequiredThroughAnnotations($ast);
		}
		
		/**
		 * Sets the single range as required when exactly one range exists.
		 *
		 * This is a simple optimization: if there's only one table in the query,
		 * it must be required (can't have a query with no results).
		 *
		 * @param AstRetrieve $ast The AST containing ranges
		 */
		private function setOnlyRangeToRequired(AstRetrieve $ast): void {
			$ranges = $ast->getRanges();
			
			if (count($ranges) === 1) {
				$ranges[0]->setRequired();
			}
		}
		
		/**
		 * Sets ranges as required based on RequiredRelation annotations.
		 *
		 * Examines ORM annotations to determine when LEFT JOINs should become INNER JOINs.
		 * A relation is considered required if it has a RequiredRelation annotation,
		 * which indicates that the related entity must always exist.
		 *
		 * @param AstRetrieve $ast The AST to analyze
		 */
		private function setRangesRequiredThroughAnnotations(AstRetrieve $ast): void {
			// Get the main table (the one without a JOIN condition)
			$mainRange = $this->getMainRange($ast);
			
			if ($mainRange === null) {
				return;
			}
			
			// Check each joined range for required annotations
			foreach ($ast->getRanges() as $range) {
				if (!$this->shouldSetRangeRequired($range)) {
					continue;
				}
				
				// Extract the join condition components
				$joinProperty = $range->getJoinProperty();
				$left = $this->binaryHelper->getBinaryLeft($joinProperty);
				$right = $this->binaryHelper->getBinaryRight($joinProperty);
				
				// Ensure we're working with the correct direction (range entity on left)
				// @phpstan-ignore-next-line method.notFound
				if ($right->getEntityName() === $range->getEntityName()) {
					[$left, $right] = [$right, $left];
				}
				
				// Verify this range is part of the join condition
				// @phpstan-ignore-next-line method.notFound
				if ($left->getEntityName() === $range->getEntityName()) {
					$this->checkAndSetRangeRequired($mainRange, $range, $left, $right);
				}
			}
		}
		
		/**
		 * Removes LEFT JOIN ranges that are not referenced anywhere in the query.
		 *
		 * This optimization eliminates unnecessary JOINs that don't contribute to
		 * the query result, improving performance by reducing database work.
		 * Only non-required ranges can be removed safely.
		 *
		 * @param AstRetrieve $ast The AST to optimize
		 */
		public function removeUnusedLeftJoinRanges(AstRetrieve $ast): void {
			$mainRange = $this->getMainRange($ast);
			
			foreach ($ast->getRanges() as $range) {
				// Never remove the main range or required ranges
				if ($range === $mainRange || $range->isRequired()) {
					continue;
				}
				
				// If no part of the query references this range, exclude it
				if ($this->isRangeCompletelyUnused($ast, $range)) {
					$range->setIncludeAsJoin(false);
				}
			}
		}
		
		/**
		 * Finds the main range (base table) in the query.
		 *
		 * The main range is identified as a database range with no join property,
		 * indicating it's the starting point for the query (FROM clause).
		 *
		 * @param AstRetrieve $ast The AST to search
		 * @return AstRangeDatabase|null The main range or null if not found
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
		 * Determines if a range should be checked for required relation annotations.
		 *
		 * Only ranges with proper join conditions (binary expressions with identifiers
		 * on both sides) are candidates for required relation checking.
		 *
		 * @param mixed $range The range to check
		 * @return bool True if the range should be checked for requirements
		 */
		private function shouldSetRangeRequired($range): bool {
			$joinProperty = $range->getJoinProperty();
			
			return $joinProperty instanceof AstExpression &&
				$joinProperty->getLeft() instanceof AstIdentifier &&
				$joinProperty->getRight() instanceof AstIdentifier;
		}
		
		/**
		 * Checks annotations and sets range as required if a matching RequiredRelation is found.
		 *
		 * This method examines the entity annotations to find RequiredRelation markers
		 * that match the current join relationship. If found, the range is marked as
		 * required, converting the LEFT JOIN to an INNER JOIN.
		 *
		 * @param AstRangeDatabase $mainRange The main table range
		 * @param AstRangeDatabase $range The range being checked
		 * @param AstIdentifier $left Left side of the join condition
		 * @param AstIdentifier $right Right side of the join condition
		 */
		private function checkAndSetRangeRequired(
			AstRangeDatabase $mainRange,
			AstRangeDatabase $range,
			AstIdentifier $left,
			AstIdentifier $right
		): void {
			// Determine which side is the main range to get correct property names
			$isMainRange = $right->getRange() === $mainRange;
			
			// Extract property and entity names based on join direction
			$ownPropertyName = $isMainRange ? $right->getName() : $left->getName();
			$ownEntityName = $isMainRange ? $right->getEntityName() : $left->getEntityName();
			$relatedPropertyName = $isMainRange ? $left->getName() : $right->getName();
			$relatedEntityName = $isMainRange ? $left->getEntityName() : $right->getEntityName();
			
			// Get all annotations for the entity that owns the relationship
			$entityAnnotations = $this->entityStore->getAnnotations($ownEntityName);
			
			// Search through all annotation groups for this entity
			foreach ($entityAnnotations as $annotations) {
				// Quick check: does this group contain any RequiredRelation annotations?
				if (!$this->containsRequiredRelationAnnotation($annotations->toArray())) {
					continue;
				}
				
				// Check each annotation in the group
				foreach ($annotations as $annotation) {
					if ($this->isMatchingRequiredRelation($annotation, $relatedEntityName, $ownPropertyName, $relatedPropertyName)) {
						// Found a matching required relation - mark range as required and exit
						$range->setRequired();
						return;
					}
				}
			}
		}
		
		/**
		 * Quick check if an annotation array contains any RequiredRelation annotations.
		 *
		 * This is a performance optimization to avoid detailed checking when
		 * no RequiredRelation annotations are present in a group.
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
		 * A relation is considered required if:
		 * - It's a ManyToOne or OneToOne relationship
		 * - The target entity matches what we're joining to
		 * - The relation column matches the join property
		 * - The inverse property matches the other side of the join
		 *
		 * @param mixed $annotation The annotation to check
		 * @param string $relatedEntityName Entity being joined to
		 * @param string $ownPropertyName Property on the owning side
		 * @param string $relatedPropertyName Property on the related side
		 * @return bool True if this annotation requires the relation
		 */
		private function isMatchingRequiredRelation(
			$annotation,
			string $relatedEntityName,
			string $ownPropertyName,
			string $relatedPropertyName
		): bool {
			return ($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
				$annotation->getTargetEntity() === $relatedEntityName &&
				$annotation->getRelationColumn() === $ownPropertyName &&
				$annotation->getInversedBy() === $relatedPropertyName;
		}
		
		/**
		 * Determines if a range is completely unused in the query.
		 *
		 * A range is considered unused if no identifiers in the query reference it.
		 * This means the joined table contributes nothing to the result set.
		 *
		 * @param AstRetrieve $ast The complete query AST
		 * @param AstRange $range The range to check for usage
		 * @return bool True if the range is not referenced anywhere
		 */
		private function isRangeCompletelyUnused(AstRetrieve $ast, AstRange $range): bool {
			// Get all identifiers that reference this range
			$allIdentifiers = $ast->getAllIdentifiers($range);
			
			// If no identifiers reference this range, it's unused
			return empty($allIdentifiers);
		}
	}