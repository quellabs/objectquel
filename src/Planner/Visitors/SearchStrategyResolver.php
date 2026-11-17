<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchFullText;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchInMemory;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchLike;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Planning-time pass that rewrites every AstSearch node in a query's WHERE
	 * clause into one of three concrete strategy nodes:
	 *
	 *   - AstSearchInMemory  — non-database ranges (JSON, temp tables); evaluated
	 *                          entirely in PHP by ConditionEvaluator.
	 *   - AstSearchFullText  — database ranges with a covering FullTextIndex;
	 *                          rendered as MATCH...AGAINST in boolean mode.
	 *   - AstSearchLike      — database ranges without a covering index;
	 *                          rendered as a LIKE / NOT LIKE chain.
	 *
	 * After this pass runs the executor receives only concrete strategy nodes and
	 * does not need to consult the EntityStore or make strategy decisions at
	 * render time.
	 */
	class SearchStrategyResolver {
		
		/** @var EntityStore Holds entity metadata */
		private EntityStore $entityStore;
		
		/**
		 * SearchStrategyResolver constructor
		 * @param EntityStore $entityStore Used to look up FullTextIndex annotations
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Collect all AstSearch nodes in $query's WHERE clause and rewrite each one
		 * into AstSearchFullText or AstSearchLike in-place via its parent reference.
		 *
		 * @param AstRetrieve $query The query whose WHERE clause will be rewritten
		 * @param array<string, mixed> $parameters Runtime parameters, used to pre-parse
		 *        LIKE term buckets when the search string is a literal AstString.
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function resolve(AstRetrieve $query, array $parameters, PlanLogInterface $log = new NullPlanLog()): void {
			// Fetch the query's conditions
			$conditions = $query->getConditions();
			
			// Nothing to rewrite if the query has no WHERE clause
			if ($conditions === null) {
				return;
			}
			
			// Collect all AstSearch nodes anywhere in the WHERE clause tree
			$collector = new CollectNodes(AstSearch::class);
			$conditions->accept($collector);

			$searchNodes = $collector->getCollectedNodes();

			if (empty($searchNodes)) {
				return;
			}

			// How many times each named :parameter appears anywhere in the query
			// (WHERE, HAVING, SORT, SELECT values, and every search()'s own search
			// string) — needed below to tell whether a search()'s own :parameter is
			// safe to unbind once consumed, or whether some other part of the query
			// (a plain comparison, or another search() reusing the same name) still
			// needs it bound.
			$parameterUsageCounts = $this->countParameterUsages($query, $searchNodes);

			// Replace each collected node in-place via its parent reference.
			// CollectNodes preserves document order, so replacements do not
			// interfere with nodes that have not yet been processed.
			foreach ($searchNodes as $searchNode) {
				// Fetch the parent
				$parent = $searchNode->getParent();

				// AstSearch always appears inside a WHERE clause, so it always has a
				// parent. A null parent here means the AST was constructed incorrectly.
				if ($parent === null) {
					throw new \LogicException('AstSearch node has no parent; cannot replace it in the tree.');
				}

				// Generate replacement
				$replacement = $this->rewriteSearch($searchNode, $parameters, $parameterUsageCounts, $log);

				// Replace the node
				AstNodeReplacer::replaceChild($parent, $searchNode, $replacement);
			}
		}

		/**
		 * Counts how many AstParameter nodes with each name appear anywhere in the
		 * query — ranges excluded, since a :parameter cannot appear in a FROM/JOIN
		 * clause. Used to tell whether a search()'s own search-string parameter is
		 * referenced only by that search() (safe to unbind once consumed) or also
		 * used elsewhere in the query (must stay bound for that other use).
		 *
		 * NodeSearch::accept() deliberately does not recurse into its own
		 * $searchString — a search() node's embedded :parameter is otherwise
		 * invisible to this collector, so every search node's own search-string
		 * parameter is counted explicitly below.
		 *
		 * @param AstRetrieve $query
		 * @param AstSearch[] $searchNodes Every search() node found in $query's WHERE clause
		 * @return array<string, int>
		 */
		private function countParameterUsages(AstRetrieve $query, array $searchNodes): array {
			$collector = new CollectNodes(AstParameter::class);
			$query->acceptWithoutRanges($collector);

			$counts = [];

			foreach ($collector->getCollectedNodes() as $parameter) {
				/**
				 * @var AstParameter $parameter
				 */
				$counts[$parameter->getName()] = ($counts[$parameter->getName()] ?? 0) + 1;
			}

			foreach ($searchNodes as $searchNode) {
				$searchString = $searchNode->getSearchString();

				if ($searchString instanceof AstParameter) {
					$counts[$searchString->getName()] = ($counts[$searchString->getName()] ?? 0) + 1;
				}
			}

			return $counts;
		}
		
		// =========================================================================
		// Node rewriting
		// =========================================================================
		
		/**
		 * Replace a single AstSearch node with the appropriate strategy node.
		 *
		 * - Non-database ranges (JSON, temp tables) → AstSearchInMemory
		 * - Database ranges with a covering FullTextIndex → AstSearchFullText
		 * - Database ranges without a covering FullTextIndex → AstSearchLike
		 *
		 * @param AstSearch $search
		 * @param array<string, mixed> $parameters
		 * @param array<string, int> $parameterUsageCounts
		 * @param PlanLogInterface $log
		 * @return AstSearchInMemory|AstSearchFullText|AstSearchLike
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function rewriteSearch(AstSearch $search, array $parameters, array $parameterUsageCounts, PlanLogInterface $log): AstSearchInMemory|AstSearchFullText|AstSearchLike {
			$identifiers = $search->getIdentifiers();
			$rangeName = $identifiers[0]->getRange()?->getName() ?? 'unknown';
			$columns = implode(', ', array_map(fn($id) => ($id->getNext()?->getName() ?? $id->getName()), $identifiers));

			// Non-database ranges have no SQL layer — produce an in-memory node so
			// the AST explicitly documents the chosen execution strategy.
			if (!$identifiers[0]->getSourceRange() instanceof AstRangeDatabase) {
				$log->note('optimizer', 'search', 'IN-MEMORY',
					"search() on [{$columns}] resolved to IN-MEMORY (non-database range)",
					$rangeName
				);

				return $this->buildInMemoryNode($identifiers, $search->getSearchString());
			}

			if ($this->detectFullTextIndex($identifiers) !== null) {
				$log->note('optimizer', 'search', 'FULLTEXT',
					"search() on [{$columns}] resolved to FULLTEXT INDEX (covering index found)",
					$rangeName
				);
				
				return $this->buildFullTextNode($identifiers, $search->getSearchString());
			} else {
				$log->note('optimizer', 'search', 'LIKE',
					"search() on [{$columns}] resolved to LIKE (no covering FULLTEXT index found)",
					$rangeName
				);
				
				return $this->buildLikeNode($search, $parameters, $parameterUsageCounts);
			}
		}
		
		/**
		 * Build an AstSearchInMemory node for non-database ranges.
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString
		 * @return AstSearchInMemory
		 */
		private function buildInMemoryNode(array $identifiers, AstString|AstParameter $searchString): AstSearchInMemory {
			return new AstSearchInMemory($identifiers, $searchString);
		}

		/**
		 * Build a full text node
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString
		 * @return AstSearchFullText
		 */
		private function buildFullTextNode(array $identifiers, AstString|AstParameter $searchString): AstSearchFullText {
			return new AstSearchFullText($identifiers, $searchString);
		}
		
		/**
		 * Build an AstSearchLike node from an AstSearch.
		 *
		 * When the search string is a literal AstString, the term buckets are parsed
		 * now so the executor receives ready-made data. When the search string is an
		 * AstParameter, $parsed is set to null because the value is only available
		 * at execution time.
		 *
		 * The search key is always generated here so that parameter names in the
		 * rendered SQL are stable and unique across multiple search() calls in the
		 * same query.
		 *
		 * When the search string is an AstParameter used nowhere else in the query,
		 * its name is recorded as "exclusive" so the executor can unbind it once
		 * consumed: the rewritten LIKE chain binds its own fresh parameters instead,
		 * and the original name no longer corresponds to any placeholder in the
		 * rendered SQL — left bound, it would be an orphaned value with no matching
		 * token, which some drivers reject outright (e.g. PDO's "number of bound
		 * variables does not match number of tokens").
		 *
		 * @param AstSearch $search
		 * @param array<string, mixed> $parameters
		 * @param array<string, int> $parameterUsageCounts
		 * @return AstSearchLike
		 * @throws QuelException
		 */
		private function buildLikeNode(AstSearch $search, array $parameters, array $parameterUsageCounts): AstSearchLike {
			// Generate a unique key now so LIKE parameter names are stable and
			// consistent across any number of re-renders of the same plan.
			$searchKey = uniqid('inject_');
			$searchString = $search->getSearchString();

			// Pre-parse only when the search string is a known literal.
			// AstParameter values are unknown until execution.
			$parsed = $searchString instanceof AstString
				? $search->parseSearchData($parameters)
				: null;

			$exclusiveParameterName = null;

			if ($searchString instanceof AstParameter && ($parameterUsageCounts[$searchString->getName()] ?? 0) === 1) {
				$exclusiveParameterName = $searchString->getName();
			}

			// Return the replacement AstSearchLike
			return new AstSearchLike(
				$search->getIdentifiers(),
				$searchString,
				$parsed,
				$searchKey,
				$exclusiveParameterName
			);
		}
		
		// =========================================================================
		// Full-text index detection
		// =========================================================================
		
		/**
		 * Return the FullTextIndex annotation if one covers all $identifiers on the
		 * same entity, or null when LIKE fallback is required.
		 *
		 * Three conditions must all hold for a fulltext index to be usable:
		 *   1. All identifiers belong to the same entity (a single MATCH() cannot
		 *      span multiple tables).
		 *   2. The entity is a real mapped entity, not a temporary table (those carry
		 *      no annotation metadata).
		 *   3. A FullTextIndex annotation covers exactly the searched properties.
		 *
		 * @param AstIdentifier[] $identifiers
		 * @return FullTextIndex|null
		 * @throws EntityResolutionException
		 */
		private function detectFullTextIndex(array $identifiers): ?FullTextIndex {
			// Do nothing when there are no identifiers
			if (empty($identifiers)) {
				return null;
			}
			
			// All identifiers must belong to the same entity: a single MATCH() call
			// cannot span multiple tables.
			$entityNames = array_unique(
				array_map(fn(AstIdentifier $id) => $id->getEntityName(), $identifiers)
			);
			
			if (count($entityNames) !== 1) {
				return null;
			}
			
			// Temporary table ranges have no entity name and therefore no annotations
			$entityName = reset($entityNames);
			
			if (empty($entityName)) {
				return null;
			}
			
			// Extract the property name from each identifier in the chain.
			// For p.name the property name is "name" (the next node in the chain).
			$propertyNames = array_map(function (AstIdentifier $id): string {
				$next = $id->getNext();
				return $next !== null ? $next->getName() : $id->getName();
			}, $identifiers);
			
			// Check the entity store for full text indexes
			$metadata = $this->entityStore->getMetadata($entityName);
			return $metadata->getFullTextIndexForColumns($propertyNames);
		}
	}