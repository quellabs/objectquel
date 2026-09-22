<?php

	namespace Quellabs\ObjectQuel\Planner\Optimizers;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AggregateRewriter;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;

	/**
	 * Rewrites an aggregate whose argument contains a nested sequence function or
	 * running aggregate (e.g. sum(o.eventTime - lag(o.eventTime sort by o.eventTime)))
	 * into two stages: the inner window computation moves into its own derived-table
	 * range, joined 1:1 back to the query's range on primary key, and the outer
	 * aggregate's argument is rewritten to reference that range's column instead.
	 *
	 * This is required regardless of whether the outer aggregate itself ends up using
	 * the window strategy - SQL cannot nest a window function inside another
	 * aggregate's argument in the same query block at all, independent of what
	 * strategy the outer aggregate uses. Once rewritten, the outer aggregate is a
	 * plain expression again and AggregateOptimizer (which runs after this pass)
	 * chooses its strategy normally.
	 *
	 * Scope: single-range queries only (matching the single-stage window strategy's
	 * own restriction) and one level of nesting per outer aggregate.
	 */
	class WindowChainRewriter extends AbstractSequenceFunctionHoister {

		/**
		 * Rewrite every aggregate in $root whose argument contains a nested
		 * sequence function or running aggregate. Mutates $root in place.
		 * @param AstRetrieve $root
		 * @param PlanLogInterface $log
		 * @return void
		 * @throws QuelException
		 */
		public function rewrite(AstRetrieve $root, PlanLogInterface $log = new NullPlanLog()): void {
			// Snapshot before mutation - the rewrite adds ranges and replaces nodes,
			// which would corrupt a live traversal.
			foreach (AstUtilities::collectAggregateNodes($root) as $outerAggregate) {
				$identifier = $outerAggregate->getIdentifier();

				if ($identifier === null) {
					continue;
				}

				$innerNodes = $this->findNestedWindowAggregates($identifier);

				if ($innerNodes === []) {
					continue;
				}

				$this->rewriteChain($root, $outerAggregate, $innerNodes, $log);
			}
		}

		/**
		 * Finds every AstAggregate within $expression that requires a window
		 * function (a sequence function, or any aggregate using an inline `sort by`
		 * and/or `by`).
		 * @param AstInterface $expression
		 * @return AstAggregate[]
		 */
		private function findNestedWindowAggregates(AstInterface $expression): array {
			/** @var CollectNodes<AstAggregate> $visitor */
			$visitor = new CollectNodes([AstAggregate::class]);
			$expression->accept($visitor);

			return array_values(array_filter(
				$visitor->getCollectedNodes(),
				fn(AstAggregate $node): bool => AstUtilities::isWindowShaped($node)
			));
		}

		/**
		 * Extracts each inner window aggregate into its own helper range and
		 * rewrites $outerAggregate's argument to reference it.
		 * @param AstRetrieve $root
		 * @param AstAggregate $outerAggregate
		 * @param AstAggregate[] $innerNodes
		 * @param PlanLogInterface $log
		 * @return void
		 * @throws QuelException
		 */
		private function rewriteChain(AstRetrieve $root, AstAggregate $outerAggregate, array $innerNodes, PlanLogInterface $log): void {
			if (!$this->platform->supportsWindowFunctions()) {
				throw new QuelException(
					'sort by inside ' . $outerAggregate->getType() . '(...) requires a database engine with window function support.'
				);
			}

			[$innerRetrieve, $clonedRange, $originalRange, $primaryKey] = $this->beginHelperRange(
				$root,
				'a nested sequence function inside ' . $outerAggregate->getType() . '(...)'
			);

			$this->helperCounter++;
			$helperRangeName = '_chain_' . $this->helperCounter;

			// Carry the outer query's own WHERE conditions (already fully resolved,
			// including any injected soft-delete/discriminator condition) into the
			// inner query too, so the window computation runs over the same row set
			// the outer query itself considers - otherwise rows excluded by the
			// outer WHERE could still influence the inner ordering/partitioning.
			$this->carryConditions($innerRetrieve, $root->getConditions(), $originalRange, $clonedRange);

			// The outer query's own non-aggregate SELECT items (minus its primary
			// key) become the outer aggregate's PARTITION BY (see AggregateOptimizer).
			// The inner lag()/rank()/etc. must partition the same way, or it would
			// compute across the whole table instead of within each of the outer
			// query's groups.
			$innerPartitionItems = $this->inferOuterPartitionItems($root, $originalRange, $clonedRange);

			// Extract each nested window aggregate into its own aliased column,
			// immediately rewriting it into a window-function subquery itself —
			// this helper query's own AggregateOptimizer pass already ran earlier
			// in the pipeline (transformNestedQueries), before this range existed,
			// so nothing else will ever do this for it.
			//
			// The identifiers built below reference the helper range by name only -
			// the range object doesn't exist yet (its own constructor needs the join
			// condition, which needs these identifiers) - so they're collected and
			// bound to the real AstRangeDatabaseSubquery once it's constructed below.
			$pendingHelperIdentifiers = [];

			foreach ($innerNodes as $index => $innerNode) {
				$seqAlias = '_seq_' . ($index + 1);
				$clonedInner = $innerNode->deepClone();
				$this->relinkIdentifiers($clonedInner, $originalRange, $clonedRange);
				$innerRetrieve->addValue(new AstAlias($seqAlias, $clonedInner));

				// An explicit inline `by` on this node wins — $clonedInner already carries
				// it relinked to $clonedRange via the relinkIdentifiers() call above, since
				// AstAggregate::accept() cascades into partitionBy like it does order.
				// Otherwise, exclude any column this specific inner node orders by (e.g.
				// `lag(o.published sort by o.published)`) — same reasoning as
				// AggregateOptimizer's STRATEGY_WINDOW case, applied per node since
				// different inner nodes in the same outer aggregate can sort by
				// different columns.
				$nodePartitionItems = AstUtilities::buildPartitionItemsFromExplicitBy($clonedInner)
					?? AstUtilities::excludeAggregateOrderColumns($innerNode, $innerPartitionItems);
				AggregateRewriter::rewriteAggregateAsWindowFunction($clonedInner, $nodePartitionItems);

				$replacement = $this->buildPropertyIdentifier(null, $seqAlias, $helperRangeName);
				$pendingHelperIdentifiers[] = $replacement;

				if ($outerAggregate->getIdentifier() === $innerNode) {
					$outerAggregate->setIdentifier($replacement);
				} else {
					$parent = $innerNode->getParent() ?? throw new \LogicException('Nested sequence function has no parent - the AST is in an invalid state.');
					AstNodeReplacer::replaceChild($parent, $innerNode, $replacement);
				}
			}

			$this->finishHelperRange($root, $innerRetrieve, $originalRange, $clonedRange, $primaryKey, $helperRangeName, $pendingHelperIdentifiers);

			$log->note(
				'optimizer', 'aggregate', 'WINDOW_CHAIN',
				"Extracted nested sequence function inside {$outerAggregate->getType()}(...) into range '{$helperRangeName}'",
				$outerAggregate->getType()
			);
		}
	}
