<?php

	namespace Quellabs\ObjectQuel\Planner\Optimizers;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;

	/**
	 * Moves a plain (non-window-shaped) aggregate condition out of WHERE and
	 * into an implicit HAVING clause - e.g. `where sum(o.total) <= 100` compiles
	 * to SQL `HAVING SUM(...) <= ?` instead of being rejected. ObjectQuel has no
	 * `having` keyword; the compiler infers the clause from the condition's
	 * shape, consistent with how a window-shaped aggregate in WHERE is already
	 * handled by WhereWindowFilterRewriter.
	 *
	 * Only moves AST subtrees between $conditions and $having - unlike
	 * WhereWindowFilterRewriter it never introduces a helper range or rewrites
	 * the aggregate node itself, since SQL HAVING can reference a plain
	 * aggregate directly.
	 */
	class WhereHavingFilterRewriter {

		/**
		 * Rewrites $root's WHERE/HAVING in place if WHERE contains a plain
		 * aggregate condition. No-op if it doesn't.
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

			[$plainLeaves, $aggregateLeaves] = $this->splitConditions($conditions);

			if ($aggregateLeaves === []) {
				return;
			}

			$root->setConditions(AstUtilities::combinePredicatesWithAnd($plainLeaves));
			$root->setHaving(AstUtilities::combinePredicatesWithAnd($aggregateLeaves));

			$log->note(
				'optimizer', 'aggregate', 'WHERE_HAVING_FILTER',
				'Moved a plain-aggregate WHERE condition into an implicit HAVING clause'
			);
		}

		/**
		 * Splits $conditions into top-level AND-ed leaves, then classifies each
		 * leaf as "plain" (no non-window-shaped aggregate anywhere in it) or
		 * "aggregate" (references at least one). Throws for the one out-of-scope
		 * shape: a plain-aggregate reference combined with OR anywhere in its own
		 * leaf - unlike WhereWindowFilterRewriter, multiple aggregate references
		 * in a single leaf are allowed, since the whole leaf just moves to HAVING
		 * unmodified.
		 * @param AstInterface $conditions
		 * @return array{0: AstInterface[], 1: AstInterface[]} [$plainLeaves, $aggregateLeaves]
		 * @throws QuelException
		 */
		private function splitConditions(AstInterface $conditions): array {
			$plainLeaves = [];
			$aggregateLeaves = [];

			foreach ($this->splitTopLevelAndConditions($conditions) as $leaf) {
				$leafAggregateNodes = $this->findPlainAggregatesIn($leaf);

				if ($leafAggregateNodes === []) {
					$plainLeaves[] = $leaf;
					continue;
				}

				if ($this->containsOrOperator($leaf)) {
					throw new QuelException(
						'Filtering on an aggregate result (e.g. sum(), count()) combined with OR has no well-defined meaning in WHERE - use a top-level AND-ed condition instead.'
					);
				}

				$aggregateLeaves[] = $leaf;
			}

			return [$plainLeaves, $aggregateLeaves];
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
		 * Finds every AstAggregate anywhere within $expression that actually needs
		 * to move to HAVING - i.e. one AggregateOptimizer will compile as a literal
		 * SQL aggregate function call, which is structurally illegal directly in
		 * WHERE. Excluded:
		 *  - AstAny (ANY(...)) - a per-row correlated boolean check with its own
		 *    dedicated rendering, not a value to filter grouped rows with;
		 *  - window-shaped aggregates - not this rewriter's job (WhereWindowFilterRewriter's);
		 *  - an aggregate with its own inline `where` and no `by` - AggregateOptimizer
		 *    always compiles that as a self-contained correlated scalar subquery
		 *    (STRATEGY_SUBQUERY_FILTERED), which is already legal directly inside
		 *    WHERE on every engine. Moving it anyway is unnecessary and breaks on
		 *    SQLite, which only recognizes a query as an aggregate query - required
		 *    for HAVING - when a literal aggregate call is visible in the outer
		 *    query, not hidden inside a nested subquery.
		 * @param AstInterface $expression
		 * @return AstAggregate[]
		 */
		private function findPlainAggregatesIn(AstInterface $expression): array {
			/** @var CollectNodes<AstAggregate> $visitor */
			$visitor = new CollectNodes([AstAggregate::class]);
			$expression->accept($visitor);

			return array_values(array_filter(
				$visitor->getCollectedNodes(),
				fn(AstAggregate $node): bool =>
					!($node instanceof AstAny) &&
					!AstUtilities::isWindowShaped($node) &&
					$node->getConditions() === null
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
