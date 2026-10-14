<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * JoinPredicateProcessor handles the processing and splitting of JOIN predicates.
	 *
	 * This class is responsible for:
	 * - Moving a live range's JOIN condition to WHERE when the range becomes a FROM range
	 * - Moving correlation-only conjuncts of an INNER JOIN condition to WHERE
	 * - Keeping LEFT JOIN conditions intact, and an OR condition whole
	 */
	class JoinPredicateProcessor {
		
		/** @var array<string, AstRange> */
		private array $liveRanges;
		
		/** @var array<string, AstRange> */
		private array $correlationOnlyRanges;
		
		/**
		 * JoinPredicateProcessor constructor
		 * @param array<string, AstRange> $liveRanges
		 * @param array<string, AstRange> $correlationOnlyRanges
		 */
		public function __construct(
			array $liveRanges,
			array $correlationOnlyRanges
		) {
			$this->correlationOnlyRanges = $correlationOnlyRanges;
			$this->liveRanges = $liveRanges;
		}
		
		/**
		 * Replaces each live range's JOIN condition with the part that stays in the JOIN.
		 * @param AstRange[] $allRanges
		 * @return AstRange[]
		 */
		public function buildUpdatedRanges(array $allRanges): array {
			foreach ($allRanges as $range) {
				if (!isset($this->liveRanges[$range->getName()]) || $range->getJoinProperty() === null) {
					continue;
				}
				
				$range->setJoinProperty($this->splitRangeJoin($range)['innerPart']);
			}
			
			return $allRanges;
		}
		
		/**
		 * Collects the JOIN condition parts of live ranges that move to WHERE. Does not mutate the ranges.
		 * @param AstRange[] $allRanges
		 * @return AstInterface[]
		 */
		public function gatherPromotedPredicates(array $allRanges): array {
			$promoted = [];
			
			foreach ($allRanges as $range) {
				if (!isset($this->liveRanges[$range->getName()]) || $range->getJoinProperty() === null) {
					continue;
				}
				
				$corrPart = $this->splitRangeJoin($range)['corrPart'];
				
				if ($corrPart !== null) {
					$promoted[] = $corrPart;
				}
			}
			
			return $promoted;
		}
		
		/**
		 * Splits a live range's JOIN condition into the part kept in the JOIN and the part moved to WHERE.
		 *
		 * - Joined to no other live range: it is a FROM range, so the whole condition moves to WHERE.
		 * - LEFT-joined to a live range: the whole condition stays, as moving part of an ON clause
		 *   to WHERE would drop the NULL-extended rows.
		 * - INNER-joined to a live range: conjuncts referencing only correlation ranges move to WHERE.
		 *
		 * @param AstRange $range A live range with a JOIN condition
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null}
		 */
		private function splitRangeJoin(AstRange $range): array {
			$joinPredicate = $range->getJoinProperty();
			$otherLiveRangeNames = array_values(array_diff(array_keys($this->liveRanges), [$range->getName()]));
			
			if (!self::referencesAnyRange($joinPredicate, $otherLiveRangeNames)) {
				return ['innerPart' => null, 'corrPart' => $joinPredicate];
			}
			
			if (!$range->isRequired()) {
				return ['innerPart' => $joinPredicate, 'corrPart' => null];
			}
			
			return self::splitJoinPredicateByRangeReferences(
				$joinPredicate,
				$otherLiveRangeNames,
				array_keys($this->correlationOnlyRanges)
			);
		}
		
		/**
		 * Returns true if the expression references one of the named ranges.
		 * @param AstInterface|null $expression
		 * @param string[] $rangeNames
		 * @return bool
		 */
		private static function referencesAnyRange(?AstInterface $expression, array $rangeNames): bool {
			foreach (AstUtilities::collectIdentifiersFromAst($expression) as $identifier) {
				if (in_array($identifier->getRange()?->getName(), $rangeNames, true)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Split a predicate into inner and correlation parts based on range references.
		 *
		 * Splits AND conjuncts recursively; any other node, including OR, is classified as a whole.
		 * Returns separate predicates for inner (live range) and correlation parts.
		 *
		 * @param AstInterface|null $predicate The JOIN predicate to split (null = no predicate)
		 * @param string[] $liveRangeNames Names of ranges considered "inner" to current subquery
		 * @param string[] $correlationRangeNames Names of ranges from outer query contexts
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split predicate parts
		 */
		private static function splitJoinPredicateByRangeReferences(
			?AstInterface $predicate,
			array         $liveRangeNames,
			array         $correlationRangeNames
		): array {
			// If no predicate set, return empty data
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			// Handle AND expressions by processing each conjunct
			if (AstUtilities::isBinaryAndOperator($predicate)) {
				return self::splitAndPredicate($predicate, $liveRangeNames, $correlationRangeNames);
			}
			
			// Leaf or OR node - classify as a whole; splitting an OR between JOIN and WHERE would change its meaning
			return match (self::classifyLeafPredicate($predicate, $liveRangeNames, $correlationRangeNames)) {
				'CORR' => ['innerPart' => null, 'corrPart' => $predicate],
				default => ['innerPart' => $predicate, 'corrPart' => null],
			};
		}
		
		/**
		 * Split AND predicate by separating conjuncts based on range references.
		 *
		 * Processes (A AND B) by classifying each conjunct independently:
		 * - Inner part: contains conjuncts referencing only live ranges
		 * - Correlation part: contains conjuncts referencing correlation ranges
		 *
		 * @param AstInterface $andPredicate The AND predicate to split
		 * @param string[] $liveRangeNames Names of ranges considered "inner"
		 * @param string[] $correlationRangeNames Names of ranges from outer contexts
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split result
		 */
		private static function splitAndPredicate(
			AstInterface $andPredicate,
			array        $liveRangeNames,
			array        $correlationRangeNames
		): array {
			$andTerms = self::flattenAndTerms($andPredicate);
			$innerTerms = [];
			$corrTerms = [];
			
			foreach ($andTerms as $term) {
				$split = self::splitJoinPredicateByRangeReferences($term, $liveRangeNames, $correlationRangeNames);
				
				if ($split['innerPart'] !== null) {
					$innerTerms[] = $split['innerPart'];
				}
				if ($split['corrPart'] !== null) {
					$corrTerms[] = $split['corrPart'];
				}
			}
			
			return [
				'innerPart' => AstUtilities::combinePredicatesWithAnd($innerTerms),
				'corrPart'  => AstUtilities::combinePredicatesWithAnd($corrTerms)
			];
		}
		
		/**
		 * Flatten AND expression tree into a flat array of conjuncts.
		 *
		 * Recursively processes nested AND operators to create a single-level array.
		 * Example: (A AND (B AND C)) becomes [A, B, C]
		 *
		 * @param AstInterface $andExpr The AND expression to flatten
		 * @return AstInterface[] Array of individual AND terms (conjuncts)
		 */
		private static function flattenAndTerms(AstInterface $andExpr): array {
			if (!AstUtilities::isBinaryAndOperator($andExpr)) {
				return [$andExpr];
			}
			
			$terms = [];
			$queue = [$andExpr];
			
			while (!empty($queue)) {
				$node = array_pop($queue);
				
				if (AstUtilities::isBinaryAndOperator($node)) {
					$queue[] = $node->getLeft();
					$queue[] = $node->getRight();
				} else {
					$terms[] = $node;
				}
			}
			
			return $terms;
		}
		
		/**
		 * Classify a leaf predicate based on which ranges it references.
		 *
		 * Analyzes all identifiers in the expression to determine classification:
		 * - INNER: references only live ranges
		 * - CORR: references only correlation ranges
		 * - MIXED: references both live and correlation ranges
		 *
		 * @param AstInterface $expr The leaf expression to classify (not an AND; an OR is classified whole)
		 * @param string[] $liveRangeNames Names of ranges considered "inner"
		 * @param string[] $correlationRangeNames Names of ranges from outer contexts
		 * @return string Classification result: 'INNER', 'CORR', or 'MIXED'
		 */
		private static function classifyLeafPredicate(
			AstInterface $expr,
			array        $liveRangeNames,
			array        $correlationRangeNames
		): string {
			$ids = AstUtilities::collectIdentifiersFromAst($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$range = $id->getRange();
				
				// Skip identifiers without range references
				if ($range === null) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				if (in_array($rangeName, $liveRangeNames, true)) {
					$hasInner = true;
				}
				
				if (in_array($rangeName, $correlationRangeNames, true)) {
					$hasCorr = true;
				}
			}
			
			// Mixed predicates stay in the JOIN
			if ($hasCorr && $hasInner) {
				return 'MIXED';
			} elseif ($hasCorr) {
				return 'CORR';
			} else {
				return 'INNER';
			}
		}
		
	}