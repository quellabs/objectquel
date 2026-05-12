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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchLike;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\Helpers\AstNodeReplacer;
	
	/**
	 * Planning-time pass that rewrites AstSearch nodes in a query's WHERE clause
	 * into either AstSearchFullText or AstSearchLike.
	 *
	 * The choice is based on whether a FullTextIndex covers all searched columns on
	 * the same entity. After this pass runs, the executor receives only the two
	 * concrete node types and does not need to consult the EntityStore or make any
	 * strategy decisions at render time.
	 */
	class SearchStrategyResolver {
		
		private EntityStore $entityStore;
		
		/**
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
		public function resolve(AstRetrieve $query, array $parameters): void {
			$conditions = $query->getConditions();
			
			// Nothing to rewrite if the query has no WHERE clause
			if ($conditions === null) {
				return;
			}
			
			// Collect all AstSearch nodes anywhere in the WHERE clause tree
			$collector = new CollectNodes(AstSearch::class);
			$conditions->accept($collector);
			
			// Replace each collected node in-place via its parent reference.
			// CollectNodes preserves document order, so replacements do not
			// interfere with nodes that have not yet been processed.
			foreach ($collector->getCollectedNodes() as $searchNode) {
				// Fetch the parent
				$parent = $searchNode->getParent();

				// AstSearch always appears inside a WHERE clause, so it always has a
				// parent. A null parent here means the AST was constructed incorrectly.
				if ($parent === null) {
					throw new \LogicException('AstSearch node has no parent; cannot replace it in the tree.');
				}

				// Generate replacement
				$replacement = $this->rewriteSearch($searchNode, $parameters);
				
				// Replace the node
				AstNodeReplacer::replaceChild($parent, $searchNode, $replacement);
			}
		}
		
		// =========================================================================
		// Node rewriting
		// =========================================================================
		
		/**
		 * Replace a single AstSearch node with AstSearchFullText or AstSearchLike.
		 * @param AstSearch $search
		 * @param array<string, mixed> $parameters
		 * @return AstSearchFullText|AstSearchLike
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function rewriteSearch(AstSearch $search, array $parameters): AstSearchFullText|AstSearchLike {
			$identifiers = $search->getIdentifiers();
			
			if ($this->detectFullTextIndex($identifiers) !== null) {
				return $this->buildFullTextNode($identifiers, $search->getSearchString());
			} else {
				return $this->buildLikeNode($search, $parameters);
			}
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
		 * @param AstSearch $search
		 * @param array<string, mixed> $parameters
		 * @return AstSearchLike
		 * @throws QuelException
		 */
		private function buildLikeNode(AstSearch $search, array $parameters): AstSearchLike {
			// Generate a unique key now so LIKE parameter names are stable and
			// consistent across any number of re-renders of the same plan.
			$searchKey = uniqid('s_', true);
			
			// Pre-parse only when the search string is a known literal.
			// AstParameter values are unknown until execution.
			$parsed = $search->getSearchString() instanceof AstString
				? $search->parseSearchData($parameters)
				: null;
			
			// Return the replacement AstSearchLike
			return new AstSearchLike(
				$search->getIdentifiers(),
				$search->getSearchString(),
				$parsed,
				$searchKey
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
			return $this->entityStore->getFullTextIndexForColumns($entityName, $propertyNames);
		}
	}