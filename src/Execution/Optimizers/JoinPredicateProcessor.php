<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * JoinPredicateProcessor handles the processing and splitting of JOIN predicates.
	 *
	 * This class is responsible for:
	 * - Extracting correlation-only predicates from JOIN clauses
	 * - Splitting JOIN predicates by range references (live vs correlation)
	 * - Classifying predicates based on which ranges they reference
	 * - Handling complex predicates with OR operators safely
	 */
	class JoinPredicateProcessor {
		
		/** @var Support\AstUtilities Utility methods for AST operations. */
		private Support\AstUtilities $astUtilities;
		
		/**
		 * JoinPredicateProcessor constructor
		 * @param Support\AstUtilities $astUtilities AST utility methods
		 */
		public function __construct(Support\AstUtilities $astUtilities) {
			$this->astUtilities = $astUtilities;
		}
		
		/**
		 * For each live range, split its JOIN predicate into INNER vs CORR parts and
		 * move the correlation-only parts into WHERE (returned as a list of predicates).
		 *
		 * Why: correlation terms referencing only "corrNames" do not belong in JOINs of
		 * live ranges. Promoting them reduces join complexity and can enable better
		 * anchor choices later.
		 *
		 * @param AstRange[] $allRanges All cloned ranges (we'll return an updated copy).
		 * @param array<string,AstRange> $liveRanges Ranges considered live (by name => range).
		 * @param string[] $liveRangeNames Names of live ranges.
		 * @param string[] $correlationRangeNames Names of correlation-only ranges.
		 * @return array{0: AstRange[], 1: AstInterface[]} [updatedRanges, promotedCorrelationPredicates]
		 */
		public function extractCorrelationPredicatesFromJoins(
			array $allRanges,
			array $liveRanges,
			array $liveRangeNames,
			array $correlationRangeNames
		): array {
			$promotedCorrelationPredicates = [];
			$updatedRanges = $allRanges;
			
			foreach ($updatedRanges as $rangeIndex => $range) {
				if (!$this->isRangeInLiveSet($range, $liveRanges)) {
					continue; // Only adjust JOINs of live ranges; others will be dropped anyway
				}
				
				$joinPredicate = $range->getJoinProperty();
				
				if ($joinPredicate === null) {
					continue; // No JOIN predicate to split/promote
				}
				
				$splitResult = $this->splitJoinPredicateByRangeReferences($joinPredicate, $liveRangeNames, $correlationRangeNames);
				
				// Update the JOIN to keep only the inner-related part
				$range->setJoinProperty($splitResult['innerPart']);
				
				// Collect correlation-only parts for promotion to WHERE clause
				if ($splitResult['corrPart'] !== null) {
					$promotedCorrelationPredicates[] = $splitResult['corrPart'];
				}
			}
			
			return [$updatedRanges, $promotedCorrelationPredicates];
		}
		
		/**
		 * Split a predicate into:
		 *   - innerPart: references only $liveRangeNames
		 *   - corrPart : references only $correlationRangeNames
		 *
		 * If a conjunct mixes inner & corr refs and contains OR, we treat it as
		 * "unsafe to split" and keep the whole predicate as innerPart.
		 *
		 * @param AstInterface|null $predicate Original JOIN predicate.
		 * @param string[] $liveRangeNames Names considered "inner".
		 * @param string[] $correlationRangeNames Names considered "correlation".
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split predicate parts
		 */
		public function splitJoinPredicateByRangeReferences(
			?AstInterface $predicate,
			array         $liveRangeNames,
			array         $correlationRangeNames
		): array {
			// When no predicate passed, return empty values
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			// If it's an AND tree, we can classify each leaf conjunct independently.
			if ($this->astUtilities->isBinaryAndOperator($predicate)) {
				$queue = [$predicate];
				$andLeaves = [];
				
				// Flatten the AND tree to a list of leaves.
				while ($queue) {
					$n = array_pop($queue);
					
					if ($this->astUtilities->isBinaryAndOperator($n)) {
						$queue[] = $n->getLeft();
						$queue[] = $n->getRight();
					} else {
						$andLeaves[] = $n;
					}
				}
				
				$innerParts = [];
				$corrParts = [];
				
				foreach ($andLeaves as $leaf) {
					$bucket = $this->classifyPredicateByRangeReferences($leaf, $liveRangeNames, $correlationRangeNames);
					
					// Unsafe: leave the entire predicate as a single innerPart.
					if ($bucket === 'MIXED_OR_COMPLEX') {
						return ['innerPart' => $predicate, 'corrPart' => null];
					}
					
					if ($bucket === 'CORR') {
						$corrParts[] = $leaf;
					} else {
						$innerParts[] = $leaf; // INNER
					}
				}
				
				return [
					'innerPart' => $this->astUtilities->combinePredicatesWithAnd($innerParts),
					'corrPart'  => $this->astUtilities->combinePredicatesWithAnd($corrParts),
				];
			}
			
			// Non-AND predicates are classified as a whole.
			return match ($this->classifyPredicateByRangeReferences($predicate, $liveRangeNames, $correlationRangeNames)) {
				'MIXED_OR_COMPLEX' => ['innerPart' => $predicate, 'corrPart' => null],
				'CORR' => ['innerPart' => null, 'corrPart' => $predicate],
				default => ['innerPart' => $predicate, 'corrPart' => null],
			};
		}
		
		/**
		 * Classify an expression by the sets of ranges it references:
		 *   - 'INNER'            : only liveRangeNames appear
		 *   - 'CORR'             : only correlationRangeNames appear
		 *   - 'MIXED_OR_COMPLEX' : both appear AND there's an OR somewhere (unsafe split)
		 *
		 * Rationale: a conjunct that mixes both sides but has no OR can be pushed
		 * into either bucket by normalization, but we keep it conservative: only
		 * split when it's clearly safe and clean.
		 *
		 * @param AstInterface $expr Expression to classify
		 * @param string[] $liveRangeNames Live range names
		 * @param string[] $correlationRangeNames Correlation range names
		 * @return 'INNER'|'CORR'|'MIXED_OR_COMPLEX' Classification result
		 */
		private function classifyPredicateByRangeReferences(AstInterface $expr, array $liveRangeNames, array $correlationRangeNames): string {
			$ids = $this->astUtilities->collectIdentifiersFromAst($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$n = $id->getRange()->getName();
				
				if (in_array($n, $liveRangeNames, true)) {
					$hasInner = true;
				}
				
				if (in_array($n, $correlationRangeNames, true)) {
					$hasCorr = true;
				}
			}
			
			// If both sides appear AND there is an OR in the subtree, splitting
			// risks changing semantics (e.g., distributing over OR). Avoid it.
			if ($this->containsOrOperator($expr) && $hasInner && $hasCorr) {
				return 'MIXED_OR_COMPLEX';
			}
			
			if ($hasCorr && !$hasInner) {
				return 'CORR';
			}
			
			return 'INNER';
		}
		
		/**
		 * True if the subtree contains an OR node anywhere.
		 * @param AstInterface $node Node to check
		 * @return bool True if OR operator found
		 */
		private function containsOrOperator(AstInterface $node): bool {
			if ($this->astUtilities->isBinaryOrOperator($node)) {
				return true;
			}
			
			foreach ($this->astUtilities->getChildrenFromBinaryOperator($node) as $child) {
				if ($this->containsOrOperator($child)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Check if a range is considered live (actively used in the query).
		 *
		 * @param AstRange $range The range to check
		 * @param array<string,AstRange> $liveRanges Map of live range names to ranges
		 * @return bool True if the range is live
		 */
		private function isRangeInLiveSet(AstRange $range, array $liveRanges): bool {
			return isset($liveRanges[$range->getName()]);
		}
	}