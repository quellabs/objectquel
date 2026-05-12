<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchFullText;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchLike;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
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
		 * Walk the WHERE conditions of $query and rewrite every AstSearch node
		 * into AstSearchFullText or AstSearchLike.
		 *
		 * @param AstRetrieve $query The query whose WHERE clause will be rewritten
		 * @param array<string, mixed> $parameters Runtime parameters, used to pre-parse
		 *        LIKE term buckets when the search string is a literal AstString.
		 */
		public function resolve(AstRetrieve $query, array $parameters): void {
			$conditions = $query->getConditions();
			
			// Nothing to rewrite if the query has no WHERE clause
			if ($conditions === null) {
				return;
			}
			
			$rewritten = $this->rewriteNode($conditions, $parameters);
			
			// Only replace the conditions reference when something actually changed
			if ($rewritten !== $conditions) {
				$query->setConditions($rewritten);
			}
		}
		
		// =========================================================================
		// Tree walk
		// =========================================================================
		
		/**
		 * Recursively walk $node and return a (possibly replaced) node.
		 *
		 * AstSearch nodes are replaced with the appropriate concrete type.
		 * AstBinaryOperator nodes (AND / OR) are cloned with rewritten children
		 * only when at least one child changed, to avoid unnecessary allocations.
		 * All other node types are returned unchanged.
		 *
		 * @param AstInterface $node
		 * @param array<string, mixed> $parameters
		 * @return AstInterface
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function rewriteNode(AstInterface $node, array $parameters): AstInterface {
			if ($node instanceof AstSearch) {
				return $this->rewriteSearch($node, $parameters);
			}
			
			// Recurse into AND / OR operator trees
			if ($node instanceof AstBinaryOperator) {
				$left = $this->rewriteNode($node->getLeft(), $parameters);
				$right = $this->rewriteNode($node->getRight(), $parameters);
				
				// Clone the operator node only if one of its children was rewritten
				if ($left !== $node->getLeft() || $right !== $node->getRight()) {
					$newNode = clone $node;
					$newNode->setLeft($left);
					$newNode->setRight($right);
					return $newNode;
				}
			}
			
			return $node;
		}
		
		/**
		 * Replace a single AstSearch node with AstSearchFullText or AstSearchLike.
		 * @param AstSearch $search
		 * @param array<string, mixed> $parameters
		 * @return AstSearchFullText|AstSearchLike
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function rewriteSearch(AstSearch $search, array $parameters): AstSearchFullText|AstSearchLike {
			$identifiers = $search->getIdentifiers();
			
			if ($this->detectFullTextIndex($identifiers) !== null) {
				return new AstSearchFullText($identifiers, $search->getSearchString());
			} else {
				return $this->buildLikeNode($search, $parameters);
			}
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