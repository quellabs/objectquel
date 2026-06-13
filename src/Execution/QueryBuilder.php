<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	
	class QueryBuilder {
		
		// Fetch mode value used by the ORM annotation system to indicate a relation
		// should be loaded on access rather than eagerly joined into the query.
		// Centralised here so any future rename (e.g. to an enum) is a one-line change.
		private const string FETCH_LAZY = 'LAZY';
		
		/**
		 * EntityStore instance
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * QueryBuilder constructor
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Composes a full ObjectQuel query string for retrieving an entity and its eager relations.
		 * @param string $entityType The entity type to retrieve.
		 * @param array<string, mixed> $primaryKeys Primary key column-to-value pairs for the WHERE clause.
		 *                                          Pass an empty array to retrieve all instances.
		 * @param array<string, string>|null $sortBy
		 * @param array<int, string> $flags Optional query flags. Supported values:
		 *                                  'ignoreSoftDelete' - suppresses the soft-delete filter,
		 *                                  used by find() so PK lookups always return the entity
		 *                                  regardless of its soft-delete state.
		 * @return string The complete query string.
		 * @throws EntityResolutionException
		 */
		public function prepareQuery(string $entityType, array $primaryKeys, ?array $sortBy = null, array $flags = []): string {
			// Collect all range definitions: 'main' plus any eagerly-joined relations.
			$relationRanges = $this->getRelationRanges($entityType);
			
			// Each range definition occupies its own line in the query header.
			$rangesImpl = implode("\n", $relationRanges);
			
			// The WHERE clause is optional: omitting it retrieves all rows for the entity.
			if (!empty($primaryKeys)) {
				$whereString = " where " . $this->parametersToString($primaryKeys);
			} else {
				$whereString = "";
			}
			
			// Sort
			$sortString = !empty($sortBy) ? " sort by " . $this->sortByToString($sortBy) : "";
			
			// Prepend the directive before the range declarations when the caller
			// wants to bypass soft-delete filtering (e.g. find() by primary key).
			$directivePrefix = in_array('ignoreSoftDelete', $flags, true) ? "@ignoreSoftDelete true\n" : "";
			
			// Final shape
			return "{$directivePrefix}{$rangesImpl}\nretrieve unique (" . implode(",", array_keys($relationRanges)) . "){$whereString}{$sortString}";
		}
		
		/**
		 * Generates a unique alias string for a range.
		 * Centralized so alias format is changed in one place only.
		 * @param int $counter
		 * @return string
		 */
		private function createAlias(int $counter): string {
			// Simple numeric suffix keeps aliases short and unambiguous in query output.
			// All alias creation goes through here so the format ("r0", "r1", ...) is
			// defined exactly once — rename it here and every range updates automatically.
			return "r{$counter}";
		}
		
		/**
		 * Iterates a set of relation metadata objects, filters out ineligible entries,
		 * and appends range definitions to $ranges for each qualifying relation.
		 *
		 * Filtering rules applied (all must pass):
		 *  - Fetch mode must not be LAZY.
		 *  - The relation's target entity must normalize to $entityType.
		 *  - If $requireNoInversedBy is true, getInversedBy() must return null
		 *    (used for OneToOne owned-side filtering).
		 *
		 * @param string $entityType The main entity type being loaded.
		 * @param string $dependentEntityType The entity type on the dependent side.
		 * @param iterable<string, ManyToOne|OneToOne> $relations Keyed by property name, values are relation metadata.
		 * @param array<string, string> $ranges Accumulator array (modified in place).
		 * @param int $rangeCounter Alias counter (modified in place).
		 * @param bool $requireNoInversedBy When true, relations with a non-null inversedBy are skipped.
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function addRanges(
			string $entityType,
			string $dependentEntityType,
			iterable $relations,
			array &$ranges,
			int &$rangeCounter,
			bool $requireNoInversedBy = false
		): void {
			foreach ($relations as $property => $relation) {
				// LAZY relations are intentionally excluded: they are resolved at
				// property-access time by the ORM proxy, not via an eager join here.
				if ($relation->getFetch() === self::FETCH_LAZY) {
					continue;
				}
				
				// normalizeEntityClass strips namespace aliases and other decoration so
				// we can do a reliable string comparison against $entityType.
				// If this relation points somewhere else entirely, it is irrelevant here.
				if ($this->entityStore->normalizeEntityClass($relation->getTargetEntity()) !== $entityType) {
					continue;
				}
				
				// For OneToOne relations we only want the *owning* side — the entity that
				// holds the foreign-key column. The inverse side (annotated with inversedBy)
				// does not own the column and must not generate a redundant range.
				if ($requireNoInversedBy && $relation->getReferencedColumn() !== null) {
					continue;
				}
				
				// The JOIN must connect the dependent range back to 'main'. Emitting
				// "via {alias}.{property}" is self-referential: the via property's parent is the
				// dependent range itself, so the rewrite collapses both sides of the join onto it
				// (e.g. r0.id = r0.user_id). Route through main's inverse side instead, which the
				// rewrite resolves to the correct condition (e.g. r0.user_id = main.id).
				$mainInverse = $this->resolveMainInverseProperty($entityType, $dependentEntityType, $property);
				
				// No back-reference on main means there is no collection or scalar to receive the
				// eager-loaded rows, so the range would serve no purpose — skip it.
				if ($mainInverse === null) {
					continue;
				}
				
				$alias = $this->createAlias($rangeCounter++);
				$ranges[$alias] = "range of {$alias} is {$dependentEntityType} via main.{$mainInverse}";
			}
		}
		
		/**
		 * Resolves the property on the main entity that is the inverse of a given owning-side
		 * relation on a dependent entity. Used to express the eager JOIN from main's side so the
		 * rewrite produces a correct condition rather than a self-referential one.
		 * @param string $mainEntityType The entity being retrieved (the 'main' range).
		 * @param string $dependentEntityType The entity that owns the relation pointing at main.
		 * @param string $owningProperty The relation property on the dependent entity (e.g. 'user').
		 * @return string|null The inverse property name on main, or null when main has no back-reference.
		 * @throws EntityResolutionException
		 */
		private function resolveMainInverseProperty(string $mainEntityType, string $dependentEntityType, string $owningProperty): ?string {
			$mainMetadata = $this->entityStore->getMetadata($mainEntityType);
			
			foreach ($mainMetadata->getInverseOfDependencies() as $property => $inverseOf) {
				// The inverse must name this exact owning-side relation and point back at the dependent.
				if ($inverseOf->getRelation() !== $owningProperty) {
					continue;
				}
				
				if ($this->entityStore->normalizeEntityClass($inverseOf->getTargetEntity()) === $dependentEntityType) {
					return $property;
				}
			}
			
			return null;
		}
		
		/**
		 * Generates an array of range definitions for the main entity and its eager relations.
		 *
		 * The first entry is always 'main'. Subsequent entries are derived from OneToOne
		 * (owned-side only) and ManyToOne relationships on each entity that declares a
		 * dependency on $entityType.
		 *
		 * @param string $entityType The entity type for which relationships should be retrieved.
		 * @return array<string, string> Range definitions keyed by alias.
		 * @throws EntityResolutionException
		 */
		private function getRelationRanges(string $entityType): array {
			// 'main' is always the first and anchor range — the entity being retrieved.
			// All other ranges are joins relative to it.
			$ranges = ['main' => "range of main is {$entityType}"];
			$rangeCounter = 0;
			
			// getDependentEntities returns every entity type that declares a relationship
			// pointing at $entityType. We inspect each one for eagerly-fetchable relations
			// and add a range for each qualifying join.
			foreach ($this->entityStore->getDependentEntities($entityType) as $dependentEntityType) {
				$metadata = $this->entityStore->getMetadata($dependentEntityType);
				
				// OneToOne: pass requireNoInversedBy=true so only the owning side
				// (the entity that actually holds the FK column) generates a range.
				$this->addRanges(
					$entityType,
					$dependentEntityType,
					$metadata->getOneToOneDependencies(),
					$ranges,
					$rangeCounter,
					requireNoInversedBy: true
				);
				
				// ManyToOne: no inversedBy restriction needed — every ManyToOne that
				// points at $entityType owns its own FK column by definition.
				$this->addRanges(
					$entityType,
					$dependentEntityType,
					$metadata->getManyToOneDependencies(),
					$ranges,
					$rangeCounter
				);
			}
			
			return $ranges;
		}
		
		/**
		 * Converts an associative array of primary-key parameters to an ObjectQuel WHERE fragment.
		 * @param array<string, mixed> $parameters Key-value pairs where keys are column/property names.
		 * @return string
		 */
		private function parametersToString(array $parameters): string {
			$parts = [];
			
			foreach ($parameters as $key => $_) {
				// Produces a condition like "main.id=:id".
				// The ":key" syntax is the named-placeholder convention understood by the
				// ObjectQuel query executor — binding happens downstream, not here.
				$parts[] = "main.{$key}=:{$key}";
			}
			
			// Multiple primary-key columns (composite keys) are ANDed together.
			return implode(" AND ", $parts);
		}
		
		/**
		 * Converts an associative array of sort parameters to an ObjectQuel SORT BY fragment.
		 * @param array<string, string> $orderBy Key-value pairs where keys are field names and values are sort directions ('ASC' or 'DESC').
		 * @return string
		 */
		private function sortByToString(array $orderBy): string {
			$parts = [];
			
			foreach ($orderBy as $field => $direction) {
				$direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
				$parts[] = "main.{$field} {$direction}";
			}
			
			return implode(", ", $parts);
		}
	}