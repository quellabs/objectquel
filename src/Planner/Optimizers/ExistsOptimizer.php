<?php

	namespace Quellabs\ObjectQuel\Planner\Optimizers;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	use Quellabs\ObjectQuel\Planner\Helpers\BinaryOperationHelper;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;

	/**
	 * Processes EXISTS operators by removing them from conditions and converting
	 * them to required ranges (INNER JOINs) for better performance.
	 *
	 * An `exists(range)` only proves the row must have a match — and so only
	 * justifies promoting `range` to an INNER JOIN and dropping the check — when
	 * it is unconditionally required, i.e. reachable from the WHERE clause's root
	 * through AND alone. Inside an OR (`a or exists(range)`), a row can satisfy
	 * the condition via the other branch with no match for `range` at all;
	 * extracting and promoting it there would silently drop such rows. Only the
	 * AND-reachable `exists()` calls are extracted and promoted; every other one
	 * is instead rewritten in place to the equivalent `<primary key> IS NOT NULL`
	 * check, which is renderable and correct regardless of its enclosing logical
	 * structure (there is no SQL rendering for a bare AstExists node).
	 */
	class ExistsOptimizer {

		/** @var EntityStore Used to resolve a range's primary key for the NOT NULL rewrite */
		private EntityStore $entityStore;

		/**
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}

		/**
		 * Main entry point for optimization. Extracts unconditionally-required EXISTS
		 * operators from the query conditions and converts them to required ranges,
		 * then rewrites any remaining EXISTS calls into NOT NULL checks.
		 * @param AstRetrieve $ast The retrieve AST node to optimize
		 */
		public function optimize(AstRetrieve $ast, PlanLogInterface $log = new NullPlanLog()): void {
			// Fetch the conditions
			$conditions = $ast->getConditions();

			// Early return if no conditions to process
			if ($conditions === null) {
				return;
			}

			// Extract AND-reachable EXISTS operators from the condition tree
			$existsList = $this->extractExistsOperators($ast, $conditions);

			// Convert each extracted EXISTS to a required range (INNER JOIN)
			foreach ($existsList as $exists) {
				$this->setRangeRequiredForExists($ast, $exists, $log);
			}

			// Anything left (inside an OR, NOT, etc.) must still become renderable SQL
			$this->replaceRemainingExistsWithNotNullChecks($ast, $log);
		}

		/**
		 * Sets the range as required for an EXISTS operation.
		 * This converts EXISTS subqueries into more efficient JOINs.
		 * @param AstRetrieve $ast The main query AST
		 * @param AstExists $exists The EXISTS operator to convert
		 */
		private function setRangeRequiredForExists(AstRetrieve $ast, AstExists $exists, PlanLogInterface $log): void {
			// Fetch the identifier from the exists node
			$identifier = $exists->getIdentifier();

			// Check that this is in fact an identifier
			if (!$identifier instanceof AstIdentifier) {
				return;
			}

			// Fetch the range from the identifier
			$existsRange = $identifier->getRange();

			// Check that the range is a database range.
			if (!$existsRange instanceof AstRangeDatabase) {
				return;
			}

			// Find the matching range in the main query and mark it as required
			foreach ($ast->getRanges() as $range) {
				if ($range->getName() === $existsRange->getName()) {
					// Set range to required
					$range->setRequired();

					// Add log
					$log->note('optimizer', 'join', 'EXISTS_TO_INNER',
						"Range '{$range->getName()}' used in EXISTS; converted to INNER JOIN",
						$range->getName()
					);
					break;
				}
			}
		}

		/**
		 * Extracts EXISTS operators that are unconditionally required — reachable
		 * from the condition tree's root through AND alone — and handles both the
		 * simple case and AND trees containing EXISTS operators. An EXISTS anywhere
		 * else (behind an OR, NOT, etc.) is left in place; replaceRemainingExistsWithNotNullChecks()
		 * rewrites it afterward.
		 * @param AstRetrieve $ast The main query AST (used for setting conditions)
		 * @param AstInterface $conditions The condition tree to process
		 * @return array<int, AstExists> Array of extracted AstExists objects
		 */
		private function extractExistsOperators(AstRetrieve $ast, AstInterface $conditions): array {
			// Simple case: single EXISTS operator
			if ($conditions instanceof AstExists) {
				$ast->setConditions(null);
				return [$conditions];
			}

			// Complex case: an AND tree potentially containing EXISTS
			if ($conditions instanceof AstBinaryOperator && strtoupper($conditions->getOperator()) === 'AND') {
				$existsList = [];
				$this->extractExistsFromAndTree($ast, $conditions, $existsList);
				return $existsList;
			}

			// Anything else (OR, a bare comparison, etc.) at the root is not
			// unconditionally required — nothing to extract here.
			return [];
		}

		/**
		 * Recursively extracts EXISTS operators from an AND tree only. Traverses
		 * AND nodes, finds EXISTS operators among their immediate operands,
		 * extracts them into a list, and reconstructs the tree without them. Stops
		 * descending at any non-AND node (OR, comparisons, …) — an EXISTS reachable
		 * only through one of those is not unconditionally required and must not be
		 * extracted or used to promote its range.
		 * @param AstInterface|null $parent The parent node (null for root)
		 * @param AstInterface $item Current node being processed
		 * @param array<int, AstExists> $list Reference to list collecting EXISTS operators
		 */
		private function extractExistsFromAndTree(?AstInterface $parent, AstInterface $item, array &$list): void {
			// Only descend into AND nodes — see this method's docblock
			if (!$item instanceof AstBinaryOperator || strtoupper($item->getOperator()) !== 'AND') {
				return;
			}

			$left = $item->getLeft();
			$right = $item->getRight();

			// Recursively process left branch if it's itself an AND node
			if ($left instanceof AstBinaryOperator) {
				$this->extractExistsFromAndTree($item, $left, $list);
			}

			// Recursively process right branch if it's itself an AND node
			if ($right instanceof AstBinaryOperator) {
				$this->extractExistsFromAndTree($item, $right, $list);
			}

			// Refresh left/right references after potential modifications from recursion
			$left = $item->getLeft();
			$right = $item->getRight();

			// Special case: both operands are EXISTS and this is the root condition
			// In this case, we remove the entire condition tree
			if ($parent instanceof AstRetrieve && $left instanceof AstExists && $right instanceof AstExists) {
				$list[] = $left;
				$list[] = $right;
				$parent->setConditions(null);
				return;
			}

			// Handle EXISTS in left operand: extract it and replace with right operand
			if ($left instanceof AstExists) {
				$list[] = $left;
				$this->setChildInParent($parent, $left, $right);
			}

			// Handle EXISTS in right operand: extract it and replace with left operand
			if ($right instanceof AstExists) {
				$list[] = $right;
				$this->setChildInParent($parent, $right, $left);
			}
		}

		/**
		 * Replaces a child node in the parent with the sibling that takes its place.
		 * Determines the correct slot (left/right) by comparing the EXISTS node
		 * identity against the parent's current children.
		 * @param AstInterface|null $parent The parent node, or null if there is no parent
		 * @param AstExists $exists The EXISTS operator being removed
		 * @param AstInterface $replacement The sibling node that will take the EXISTS operator's place
		 */
		private function setChildInParent(?AstInterface $parent, AstExists $exists, AstInterface $replacement): void {
			// If parent is the root AstRetrieve, replace the entire condition with the sibling
			if ($parent instanceof AstRetrieve) {
				$parent->setConditions($replacement);
				return;
			}

			// Null parent or non-binary nodes cannot have children set
			if (!$parent instanceof NodeBinary) {
				return;
			}

			// Determine which slot the EXISTS occupied by identity comparison,
			// then put the sibling in its place
			if (BinaryOperationHelper::getBinaryLeft($parent) === $exists) {
				BinaryOperationHelper::setBinaryLeft($parent, $replacement);
			} else {
				BinaryOperationHelper::setBinaryRight($parent, $replacement);
			}
		}

		/**
		 * Rewrites every EXISTS call still left in the conditions (anything not
		 * extracted above — behind an OR, a NOT, etc.) into the equivalent
		 * `<primary key> IS NOT NULL` check on that range. There is no SQL
		 * rendering for a bare AstExists node, so one must never be left in the
		 * final tree; unlike extraction, this rewrite does not promote the range,
		 * since a match is no longer guaranteed for every row.
		 * @param AstRetrieve $ast The main query AST
		 * @param PlanLogInterface $log
		 */
		private function replaceRemainingExistsWithNotNullChecks(AstRetrieve $ast, PlanLogInterface $log): void {
			$conditions = $ast->getConditions();

			if ($conditions === null) {
				return;
			}

			$collector = new CollectNodes(AstExists::class);
			$conditions->accept($collector);

			foreach ($collector->getCollectedNodes() as $exists) {
				$this->replaceExistsWithNotNullCheck($exists, $log);
			}
		}

		/**
		 * Replaces a single EXISTS node in place with `<range>.<primary key> IS NOT NULL`.
		 * @param AstExists $exists
		 * @param PlanLogInterface $log
		 */
		private function replaceExistsWithNotNullCheck(AstExists $exists, PlanLogInterface $log): void {
			$identifier = $exists->getIdentifier();

			if (!$identifier instanceof AstIdentifier) {
				return;
			}

			$range = $identifier->getRange();

			if (!$range instanceof AstRangeDatabase) {
				return;
			}

			$primaryKey = $this->entityStore->getMetadata($range->getEntityName())->getPrimaryKey();

			// No resolvable primary key to check — leave the node as-is rather than guess
			if ($primaryKey === null) {
				return;
			}

			$parent = $exists->getParent();

			if ($parent === null) {
				return;
			}

			$pkIdentifier = new AstIdentifier($range->getName(), IdentifierType::EntityRoot);
			$pkIdentifier->setRange($range);
			$pkIdentifier->setNext(new AstIdentifier($primaryKey, IdentifierType::EntityProperty));

			AstNodeReplacer::replaceChild($parent, $exists, new AstCheckNotNull($pkIdentifier));

			$log->note('optimizer', 'join', 'EXISTS_TO_NOT_NULL',
				"exists({$range->getName()}) is not unconditionally required (reachable through OR/NOT); rewritten to a NOT NULL check instead of forcing INNER JOIN",
				$range->getName()
			);
		}
	}
