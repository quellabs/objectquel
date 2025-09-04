<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	
	class ExistsRewriter {
		
		/**
		 * When SELECT is aggregates-only, rewrite join edges that merely filter
		 * (and do not feed aggregates/WHERE) into EXISTS subqueries.
		 * @param AstRetrieve $root Query to mutate
		 * @param array $aggregateRangeMap
		 * @return void
		 */
		public static function rewriteFilterOnlyJoinsAsExists(AstRetrieve $root, array $aggregateRangeMap): void {
			$ranges = $root->getRanges();
			
			if (count($ranges) < 2) {
				return;
			}
			
			$outerWhere = $root->getConditions();
			$rangesInWhere = $outerWhere ? RangeUtilities::collectRangesFromNode($outerWhere) : [];
			
			foreach ($ranges as $hostRange) {
				$hostJoin = $hostRange->getJoinProperty();
				
				if ($hostJoin === null) {
					continue;
				}
				
				$joinRefs = RangeUtilities::collectRangesFromNode($hostJoin);
				
				foreach ($joinRefs as $refRange) {
					$refHash = spl_object_hash($refRange);
					$feedsAgg = isset($aggregateRangeMap[$refHash]);
					$feedsWhere = in_array($refRange, $rangesInWhere, true);
					
					if ($feedsAgg || $feedsWhere) {
						continue;
					}
					
					// Build EXISTS using a clone of the referenced range
					$clonedRef = $refRange->deepClone();
					$rebasedWhere = self::rebindPredicateToClone($hostJoin, $refRange, $clonedRef);
					
					$exists = new AstSubquery(
						AstSubquery::TYPE_EXISTS,
						new AstNumber(1),
						[$clonedRef],
						$rebasedWhere
					);
					
					$outerWhere = $outerWhere ? new AstBinaryOperator($outerWhere, $exists, 'AND') : $exists;
					$root->setConditions($outerWhere);
					
					$hostRange->setJoinProperty(null);
					$root->removeRange($refRange);
				}
			}
		}
		
		/**
		 * Replace EXISTS(SelfJoin) with NOT NULL checks on the outer side (or TRUE if nulls allowed).
		 * @param AstRetrieve $root Query to mutate
		 * @param bool $includeNulls If true, EXISTS collapses to TRUE
		 * @return void
		 */
		public static function simplifySelfJoinExists(AstRetrieve $root, bool $includeNulls): void {
			$where = $root->getConditions();
			if ($where === null) {
				return;
			}
			
			$rewritten = self::rewriteExistsNodesWithinAndTree($where, $includeNulls);
			if ($rewritten !== $where) {
				$root->setConditions($rewritten);
			}
		}
		
		/**
		 * Recursively traverse an AND-tree and simplify eligible EXISTS nodes.
		 *
		 * This function performs a depth-first traversal of an AST subtree rooted at an AND operator,
		 * looking for EXISTS subqueries that can be optimized. The optimization typically converts
		 * self-join EXISTS patterns into simpler predicates.
		 *
		 * @param AstInterface $node Root of the subtree to process
		 * @param bool $includeNulls If true, replace simplified EXISTS with TRUE predicate;
		 *                           if false, replace with more restrictive predicate that excludes nulls
		 * @return AstInterface Possibly rewritten node (new instance if changes made, original if unchanged)
		 */
		public static function rewriteExistsNodesWithinAndTree(AstInterface $node, bool $includeNulls): AstInterface {
			// Handle AND nodes: recursively process both sides of the binary operator
			if ($node instanceof AstBinaryOperator && $node->getOperator() === 'AND') {
				// Recursively rewrite left and right subtrees
				$l = self::rewriteExistsNodesWithinAndTree($node->getLeft(), $includeNulls);
				$r = self::rewriteExistsNodesWithinAndTree($node->getRight(), $includeNulls);
				
				// Optimization: only create new node if children actually changed
				// This preserves object identity when no rewriting occurred
				if ($l === $node->getLeft() && $r === $node->getRight()) {
					return $node;
				}
				
				// Create new AND node with potentially rewritten children
				return new AstBinaryOperator($l, $r, 'AND');
			}
			
			// Handle EXISTS subqueries: attempt to simplify self-join patterns
			if ($node instanceof AstSubquery && $node->getType() === AstSubquery::TYPE_EXISTS) {
				// Delegate to specialized method that analyzes the EXISTS subquery structure
				// Returns null if no simplification is possible, otherwise returns replacement predicate
				$replacement = self::simplifySingleExistsNodeIfSelfJoin($node, $includeNulls);
				
				// Successfully simplified: return the replacement predicate
				if ($replacement !== null) {
					return $replacement;
				}
				
				// No simplification possible: fall through to return original node
			}
			
			// Base case: node is not an AND operator or EXISTS subquery, or couldn't be simplified
			// Return unchanged (this includes other operators like OR, comparison operators, literals, etc.)
			return $node;
		}
		
		/**
		 * Simplifies EXISTS(subquery) to NOT NULL checks when it represents a trivial self-join.
		 *
		 * A self-join is considered trivial when:
		 * - The subquery joins the same entity on identical columns
		 * - The join condition only compares outer.col = inner.col patterns
		 * - No additional WHERE conditions exist beyond the join predicates
		 *
		 * Example transformation:
		 * EXISTS(SELECT 1 FROM users u2 WHERE u1.id = u2.id AND u1.name = u2.name)
		 * becomes: u1.id IS NOT NULL AND u1.name IS NOT NULL
		 *
		 * @param AstSubquery $existsNode The EXISTS subquery to analyze
		 * @param bool $includeNulls When true, returns TRUE predicate (includes NULL values)
		 *                           When false, generates NOT NULL checks for join columns
		 * @return AstInterface|null Simplified predicate, or null if optimization not applicable
		 * @throws \InvalidArgumentException If subquery structure is malformed
		 */
		public static function simplifySingleExistsNodeIfSelfJoin(AstSubquery $existsNode, bool $includeNulls): ?AstInterface {
			// Early exit: self-join optimization requires correlated ranges
			// Non-correlated subqueries cannot be simplified to column checks
			$correlatedRanges = $existsNode->getCorrelatedRanges();
			if (empty($correlatedRanges)) {
				return null;
			}
			
			// Build hash-based lookup for O(1) correlated range identification
			// Used later to distinguish outer vs inner table references in join conditions
			$correlatedRangeSet = [];
			foreach ($correlatedRanges as $range) {
				$correlatedRangeSet[spl_object_hash($range)] = true;
			}
			
			// Extract WHERE clause conditions from the subquery
			// These must contain only equality joins for the optimization to apply
			$conditions = $existsNode->getConditions();
			if ($conditions === null) {
				return null;
			}
			
			// Parse join conditions into outer/inner column pairs
			// Only simple equality conditions (outer.col = inner.col) are supported
			$joinPairs = [];
			
			if (!self::collectOuterInnerIdPairs($conditions, $correlatedRangeSet, $joinPairs) || empty($joinPairs)) {
				return null;
			}
			
			// Verify structural requirements for self-join optimization:
			// - Same underlying table/entity for outer and inner references
			// - Identical column sets being compared
			// - No complex expressions or transformations in join predicates
			if (!self::isValidSelfJoin($joinPairs)) {
				return null;
			}
			
			// Special optimization case: when includeNulls=true, the EXISTS becomes trivial
			// Since we're checking if a row exists in the same table with same values,
			// and NULLs are included, this is always true (assuming the outer row exists)
			if ($includeNulls) {
				// Return literal TRUE condition (1=1)
				return new AstExpression(new AstNumber(1), new AstNumber(1), '=');
			}
			
			// Standard case: transform EXISTS to conjunction of NOT NULL checks
			// Logic: EXISTS(SELECT ... WHERE outer.a=inner.a AND outer.b=inner.b)
			// is equivalent to (outer.a IS NOT NULL AND outer.b IS NOT NULL)
			// because if outer columns are non-null, the self-join will always find the same row
			return self::buildNotNullChain($joinPairs);
		}
		
		/**
		 * Scans a predicate tree for simple equality comparisons (id = id) where
		 * exactly one identifier belongs to an inner range and one to an outer range.
		 * Only processes AND operations and equality expressions.
		 * @param AstInterface $expr The predicate subtree to analyze
		 * @param array<string,bool> $innerSet Hash map of inner ranges (spl_object_hash => true)
		 * @param array<int,array{0:AstIdentifier,1:AstIdentifier}> &$pairs Output array of [outer, inner] pairs
		 * @return bool True if all leaves are eligible equality comparisons, false otherwise
		 */
		public static function collectOuterInnerIdPairs(AstInterface $expr, array $innerSet, array &$pairs): bool {
			// Recursively process AND operations
			if ($expr instanceof AstBinaryOperator && $expr->getOperator() === 'AND') {
				return
					self::collectOuterInnerIdPairs($expr->getLeft(), $innerSet, $pairs) &&
					self::collectOuterInnerIdPairs($expr->getRight(), $innerSet, $pairs);
			}
			
			// Process equality expressions
			if ($expr instanceof AstExpression && $expr->getOperator() === '=') {
				$leftOperand = $expr->getLeft();
				$rightOperand = $expr->getRight();
				
				// Both operands must be identifiers
				if (!($leftOperand instanceof AstIdentifier) || !($rightOperand instanceof AstIdentifier)) {
					return false;
				}
				
				// Get base identifiers and their ranges
				$leftBase = $leftOperand->getBaseIdentifier();
				$rightBase = $rightOperand->getBaseIdentifier();
				$leftRange = $leftBase->getRange();
				$rightRange = $rightBase->getRange();
				
				// Both identifiers must have valid ranges
				if ($leftRange === null || $rightRange === null) {
					return false;
				}
				
				// Check which identifiers belong to inner ranges
				$leftIsInner = isset($innerSet[spl_object_hash($leftRange)]);
				$rightIsInner = isset($innerSet[spl_object_hash($rightRange)]);
				
				// Exactly one identifier must be inner, one outer
				if ($leftIsInner === $rightIsInner) {
					return false; // Both inner or both outer - not eligible
				}
				
				// Store in canonical order: [outer identifier, inner identifier]
				if ($leftIsInner) {
					$pairs[] = [$rightOperand, $leftOperand]; // right=outer, left=inner
				} else {
					$pairs[] = [$leftOperand, $rightOperand]; // left=outer, right=inner
				}
				
				return true;
			}
			
			// All other expression types are ineligible
			return false;
		}
		
		/**
		 * Validates that all join pairs represent a self-join on the same entity and properties.
		 * @param array $joinPairs
		 * @return bool
		 */
		public static function isValidSelfJoin(array $joinPairs): bool {
			foreach ($joinPairs as [$outerIdentifier, $innerIdentifier]) {
				if (!self::isSameEntityAndProperty($outerIdentifier, $innerIdentifier)) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Checks if two identifiers reference the same entity and property.
		 * @param AstIdentifier $outer
		 * @param AstIdentifier $inner
		 * @return bool
		 */
		public static function isSameEntityAndProperty(AstIdentifier $outer, AstIdentifier $inner): bool {
			// Verify both identifiers have valid ranges
			$outerRange = $outer->getBaseIdentifier()->getRange();
			$innerRange = $inner->getBaseIdentifier()->getRange();
			
			if ($outerRange === null || $innerRange === null) {
				return false;
			}
			
			// Must be the same entity
			if ($outerRange->getEntityName() !== $innerRange->getEntityName()) {
				return false;
			}
			
			// Must reference the same property
			$outerProperty = $outer->getPropertyName();
			$innerProperty = $inner->getPropertyName();
			
			return
				$outerProperty !== '' &&
				$innerProperty !== '' &&
				$outerProperty === $innerProperty;
		}
		
		/**
		 * Builds a chain of IS NOT NULL conditions connected by AND operators.
		 */
		public static function buildNotNullChain(array $joinPairs): ?AstInterface {
			$notNullChain = null;
			
			foreach ($joinPairs as [$outerIdentifier, $_innerIdentifier]) {
				$notNullCheck = new AstCheckNotNull($outerIdentifier->deepClone());
				
				if ($notNullChain === null) {
					$notNullChain = $notNullCheck;
				} else {
					$notNullChain = new AstBinaryOperator($notNullChain, $notNullCheck, 'AND');
				}
			}
			
			return $notNullChain;
		}
		
		/**
		 * Clone a predicate and retarget identifiers pointing to $oldRange so that they
		 * point to $newRange.
		 * @param AstInterface $predicate Predicate to clone
		 * @param AstRange $oldRange Original range
		 * @param AstRange $newRange Replacement range
		 * @return AstInterface Cloned predicate with identifiers rebound
		 */
		public static function rebindPredicateToClone(AstInterface $predicate, AstRange $oldRange, AstRange $newRange): AstInterface {
			$cloned = $predicate->deepClone();
			
			$visitor = new CollectIdentifiers();
			$cloned->accept($visitor);
			$ids = $visitor->getCollectedNodes();
			
			foreach ($ids as $id) {
				if ($id->getRange() === $oldRange) {
					$id->setRange($newRange);
				}
			}
			
			return $cloned;
		}
	}