<?php

	namespace Quellabs\ObjectQuel\Planner\Optimizers;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AggregateRewriter;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;

	/**
	 * Rewrites a WHERE condition that filters on a window-shaped sequence
	 * function's result (e.g. `where rn <= 3` where `rn = row_number(sort by
	 * o.id)`) - the "top N per group" pattern - into the same implicit
	 * derived-table strategy WindowChainRewriter uses for a nested sequence
	 * function: the window computation moves into its own helper range, joined
	 * 1:1 back to the query's range on primary key, and the outer WHERE
	 * condition is rewritten to compare that range's column instead.
	 *
	 * This is required because SQL forbids referencing a window function's
	 * result in the same query block's WHERE (window functions evaluate after
	 * WHERE) - the standard workaround is to compute it in one query stage
	 * (a CTE, or here, an equivalent derived-table subquery) and filter on it
	 * in an outer stage.
	 *
	 * Scope (v1, matching WindowChainRewriter's own restrictions):
	 * - Single database-range queries only.
	 * - Only window-shaped sequence functions (AstUtilities::isWindowShaped());
	 *   a plain aggregate in WHERE is rejected earlier, by SemanticAnalyzer.
	 * - The sequence-function reference must sit inside a top-level AND-ed WHERE
	 *   condition - one that isn't itself combined with an OR anywhere in its
	 *   own subtree ("which rows belong in the ranking" has no defined meaning
	 *   otherwise) - and there must be exactly one such reference per condition.
	 */
	class WhereWindowFilterRewriter extends AbstractSequenceFunctionHoister {

		/**
		 * Rewrites $root's WHERE in place if it filters on one or more
		 * window-shaped sequence-function results. No-op if it doesn't.
		 * @param AstRetrieve $root
		 * @param PlanLogInterface $log
		 * @return void
		 * @throws QuelException
		 */
		public function rewrite(AstRetrieve $root, PlanLogInterface $log = new NullPlanLog()): void {
			$conditions = $root->getConditions();

			if ($conditions === null) {
				return;
			}

			[$plainLeaves, $windowNodes] = $this->splitConditions($conditions);

			if ($windowNodes === []) {
				return;
			}

			if (!$this->platform->supportsWindowFunctions()) {
				throw new QuelException(
					'Filtering on a sequence-function result (e.g. rank(), row_number()) in WHERE requires a database engine with window function support.'
				);
			}

			[$innerRetrieve, $clonedRange, $originalRange, $primaryKey] = $this->beginHelperRange(
				$root,
				'a sequence-function filter in WHERE'
			);

			$this->helperCounter++;
			$helperRangeName = '_where_seq_' . $this->helperCounter;

			// Carry every other top-level AND-ed WHERE condition (the ones that
			// don't reference a sequence function) into the helper's own WHERE, so
			// the ranking computes over the same filtered row set the outer query
			// intends - same reasoning as WindowChainRewriter carrying the whole
			// outer WHERE, but here the source is only the non-window leaves.
			$plainConditions = null;

			foreach ($plainLeaves as $leaf) {
				// Cloned up front so combining them below (which reparents each
				// node onto a synthetic AND wrapper) never touches the real,
				// still-attached leaf objects in $root's own WHERE tree.
				$clonedLeaf = $leaf->deepClone();
				$plainConditions = $plainConditions === null
					? $clonedLeaf
					: AstUtilities::combinePredicatesWithAnd([$plainConditions, $clonedLeaf]);
			}

			$this->carryConditions($innerRetrieve, $plainConditions, $originalRange, $clonedRange);

			$innerPartitionItems = $this->inferOuterPartitionItems($root, $originalRange, $clonedRange);

			$pendingHelperIdentifiers = [];

			foreach ($windowNodes as $index => $innerNode) {
				$seqAlias = '_seq_' . ($index + 1);
				$clonedInner = $innerNode->deepClone();
				$this->relinkIdentifiers($clonedInner, $originalRange, $clonedRange);
				$innerRetrieve->addValue(new AstAlias($seqAlias, $clonedInner));

				$nodePartitionItems = AstUtilities::buildPartitionItemsFromExplicitBy($clonedInner)
					?? AstUtilities::excludeAggregateOrderColumns($innerNode, $innerPartitionItems);
				AggregateRewriter::rewriteAggregateAsWindowFunction($clonedInner, $nodePartitionItems);

				$replacement = $this->buildPropertyIdentifier(null, $seqAlias, $helperRangeName);
				$pendingHelperIdentifiers[] = $replacement;

				// Rewrite the outer WHERE condition to compare the helper range's
				// column instead of the raw sequence-function call - the original
				// node stays exactly where it was found (still attached to $root's
				// WHERE tree); only this one occurrence is swapped out.
				$parent = $innerNode->getParent() ?? throw new \LogicException('Sequence function referenced in WHERE has no parent - the AST is in an invalid state.');
				AstNodeReplacer::replaceChild($parent, $innerNode, $replacement);
			}

			$this->finishHelperRange($root, $innerRetrieve, $originalRange, $clonedRange, $primaryKey, $helperRangeName, $pendingHelperIdentifiers);

			$log->note(
				'optimizer', 'aggregate', 'WHERE_WINDOW_FILTER',
				"Extracted sequence-function filter from WHERE into range '{$helperRangeName}'",
				$helperRangeName
			);
		}

		/**
		 * Splits $conditions into top-level AND-ed leaves, then classifies each
		 * leaf as "plain" (no window-shaped sequence function anywhere in it) or
		 * "window" (references exactly one). Throws for the two out-of-scope
		 * shapes: a sequence-function reference combined with OR anywhere in its
		 * own leaf, and more than one sequence-function reference in a single leaf.
		 * @param AstInterface $conditions
		 * @return array{0: AstInterface[], 1: AstAggregate[]} [$plainLeaves, $windowNodes]
		 * @throws QuelException
		 */
		private function splitConditions(AstInterface $conditions): array {
			$plainLeaves = [];
			$windowNodes = [];

			foreach ($this->splitTopLevelAndConditions($conditions) as $leaf) {
				$leafWindowNodes = $this->findWindowAggregatesIn($leaf);

				if ($leafWindowNodes === []) {
					$plainLeaves[] = $leaf;
					continue;
				}

				if ($this->containsOrOperator($leaf)) {
					throw new QuelException(
						'Filtering on a sequence-function result (e.g. rank(), row_number()) combined with OR has no well-defined meaning in WHERE - use a top-level AND-ed condition instead.'
					);
				}

				if (count($leafWindowNodes) > 1) {
					throw new QuelException(
						'Combining more than one sequence-function result in a single WHERE condition (e.g. rn + rn2 <= 3) is not supported.'
					);
				}

				$windowNodes[] = $leafWindowNodes[0];
			}

			return [$plainLeaves, $windowNodes];
		}

		/**
		 * Flattens a top-level AND tree into its leaves. Descent stops at any node
		 * that isn't itself an AND (including an OR node, which is returned whole
		 * as a single leaf) - so an OR only ever appears nested inside a leaf, never
		 * split across leaves.
		 * @param AstInterface $conditions
		 * @return AstInterface[]
		 */
		private function splitTopLevelAndConditions(AstInterface $conditions): array {
			if (!AstUtilities::isBinaryAndOperator($conditions)) {
				return [$conditions];
			}

			/** @var AstBinaryOperator $conditions */
			return array_merge(
				$this->splitTopLevelAndConditions($conditions->getLeft()),
				$this->splitTopLevelAndConditions($conditions->getRight())
			);
		}

		/**
		 * Finds every window-shaped AstAggregate anywhere within $expression.
		 * @param AstInterface $expression
		 * @return AstAggregate[]
		 */
		private function findWindowAggregatesIn(AstInterface $expression): array {
			/** @var CollectNodes<AstAggregate> $visitor */
			$visitor = new CollectNodes([AstAggregate::class]);
			$expression->accept($visitor);

			return array_values(array_filter(
				$visitor->getCollectedNodes(),
				fn(AstAggregate $node): bool => AstUtilities::isWindowShaped($node)
			));
		}

		/**
		 * Returns true if $expression contains an OR operator anywhere in its subtree.
		 * @param AstInterface $expression
		 * @return bool
		 */
		private function containsOrOperator(AstInterface $expression): bool {
			$visitor = new CollectNodes([AstBinaryOperator::class]);
			$expression->accept($visitor);

			foreach ($visitor->getCollectedNodes() as $node) {
				if (AstUtilities::isBinaryOrOperator($node)) {
					return true;
				}
			}

			return false;
		}
	}
