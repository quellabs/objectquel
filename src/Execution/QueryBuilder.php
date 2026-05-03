<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityStore;
	
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
		 * @return string The complete query string.
		 */
		public function prepareQuery(string $entityType, array $primaryKeys): string {
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
			
			// Final shape:
			//   range of main is App\Entity\Order
			//   range of r0 is App\Entity\OrderLine via r0.orderId=main.id
			//   retrieve unique (main,r0) where main.id=:id
			//
			// "retrieve unique" tells the executor to deduplicate result rows, which is
			// necessary because the joins can fan out (one Order → many OrderLines).
			return "{$rangesImpl}\nretrieve unique (" . implode(",", array_keys($relationRanges)) . "){$whereString}";
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
		 * Builds the range definition string for a dependent entity joined via a relation column.
		 * @param string $alias The alias to use for the dependent entity range.
		 * @param string $dependentEntityType The fully-qualified class name of the dependent entity.
		 * @param string $entityType The main entity type (used to resolve the foreign/primary key).
		 * @param string $property The property name on the dependent entity that holds the relation.
		 * @param object $relation The relation annotation/metadata object.
		 * @return string
		 * @throws \RuntimeException If the main entity has no primary key and none is specified on the relation.
		 */
		private function buildRangeString(string $alias, string $dependentEntityType, string $entityType, string $property, object $relation): string {
			// The relation column is the foreign-key column on the *dependent* side.
			// The annotation may declare it explicitly; if not, we fall back to the
			// conventional "{propertyName}Id" naming (e.g. property "order" → "orderId").
			$relationColumn = $relation->getRelationColumn() ?? "{$property}Id";
			
			// The foreign column is the referenced column on the *main* entity side —
			// typically the primary key. Preference order:
			//   1. Explicitly declared on the annotation (getForeignColumn).
			//   2. The entity's primary key as reported by EntityStore.
			//   3. Neither available → programming error; throw immediately rather than
			//      silently producing a broken join clause.
			$foreignColumn = $relation->getForeignColumn()
				?? $this->entityStore->getPrimaryKey($entityType)
				?? throw new \RuntimeException("Entity '{$entityType}' has no primary key defined.");
			
			// Produces a clause like:
			//   range of r0 is App\Entity\OrderLine via r0.orderId=main.id
			// The "via" predicate is what the query executor uses to perform the join.
			return "range of {$alias} is {$dependentEntityType} via {$alias}.{$relationColumn}=main.{$foreignColumn}";
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
		 * Duplicate detection: if the exact same range body has already been registered,
		 * it is skipped. This guards against multiple dependencies resolving to identical
		 * join clauses.
		 *
		 * @param string $entityType The main entity type.
		 * @param string $dependentEntityType The entity type on the dependent side.
		 * @param iterable<string, object> $relations Keyed by property name, values are relation metadata.
		 * @param array<string,string> $ranges Accumulator array (modified in place).
		 * @param int $rangeCounter Alias counter (modified in place).
		 * @param bool $requireNoInversedBy When true, relations with a non-null inversedBy are skipped.
		 * @return void
		 */
		private function addRanges(
			string   $entityType,
			string   $dependentEntityType,
			iterable $relations,
			array    &$ranges,
			int      &$rangeCounter,
			bool     $requireNoInversedBy = false
		): void {
			// Build a lookup of range "bodies" that are already registered.
			// A body is the range string with its alias-specific prefix stripped, i.e.
			// everything from "is <Type> via ..." onward. Two ranges with different
			// aliases but identical bodies represent the same join and must not both
			// be emitted — the query executor would treat them as duplicate joins.
			$existingRangeBodies = array_map(
				static fn(string $r): string => preg_replace('/^range of \S+ /', '', $r),
				$ranges
			);
			
			foreach ($relations as $property => $relation) {
				// LAZY relations are intentionally excluded: they are resolved at
				// property-access time by the ORM proxy, not via an eager join here.
				if ($relation->getFetch() === self::FETCH_LAZY) {
					continue;
				}
				
				// normalizeEntityName strips namespace aliases and other decoration so
				// we can do a reliable string comparison against $entityType.
				// If this relation points somewhere else entirely, it is irrelevant here.
				if ($this->entityStore->normalizeEntityName($relation->getTargetEntity()) !== $entityType) {
					continue;
				}
				
				// For OneToOne relations we only want the *owning* side — the entity that
				// holds the foreign-key column. The inverse side (annotated with inversedBy)
				// does not own the column and must not generate a redundant range.
				if ($requireNoInversedBy && $relation->getInversedBy() !== null) {
					continue;
				}
				
				$alias = $this->createAlias($rangeCounter);
				$rangeString = $this->buildRangeString($alias, $dependentEntityType, $entityType, $property, $relation);
				
				// Strip the alias token from the new range string so we can compare its
				// body against the pre-built lookup. The alias itself is irrelevant for
				// determining whether the join clause is a duplicate.
				$rangeBody = preg_replace('/^range of \S+ /', '', $rangeString);
				
				// Skip if an identical join clause is already in $ranges. This can happen
				// when two different dependent entities both declare a relation to $entityType
				// through the same column pair.
				if (in_array($rangeBody, $existingRangeBodies, true)) {
					continue;
				}
				
				// Register the new range and keep the body lookup in sync so subsequent
				// iterations within this call can also detect duplicates against it.
				$ranges[$alias] = $rangeString;
				$existingRangeBodies[] = $rangeBody;
				
				// Advance the counter only when a range is actually added so that
				// alias numbers remain gapless (r0, r1, r2, ...) regardless of how
				// many relations were filtered out.
				++$rangeCounter;
			}
		}
		
		/**
		 * Generates an array of range definitions for the main entity and its relationships.
		 *
		 * The first entry is always 'main'. Subsequent entries are derived from OneToOne
		 * (owned-side only) and ManyToOne relationships on each entity that declares a
		 * dependency on $entityType.
		 *
		 * @param string $entityType The entity type for which relationships should be retrieved.
		 * @return array<string, string> Range definitions keyed by alias.
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
				// OneToOne: pass requireNoInversedBy=true so only the owning side
				// (the entity that actually holds the FK column) generates a range.
				$this->addRanges(
					$entityType,
					$dependentEntityType,
					$this->entityStore->getOneToOneDependencies($dependentEntityType),
					$ranges,
					$rangeCounter,
					requireNoInversedBy: true
				);
				
				// ManyToOne: no inversedBy restriction needed — every ManyToOne that
				// points at $entityType owns its own FK column by definition.
				$this->addRanges(
					$entityType,
					$dependentEntityType,
					$this->entityStore->getManyToOneDependencies($dependentEntityType),
					$ranges,
					$rangeCounter
				);
			}
			
			return $ranges;
		}
		
		/**
		 * Converts an associative array of primary-key parameters to an ObjectQuel WHERE fragment.
		 *
		 * Each entry becomes: "{$prefix}.{$key}=:{$key}", joined by " AND ".
		 *
		 * Note: key names are used verbatim as named placeholders. The caller is responsible
		 * for ensuring that keys are valid identifiers and that the downstream query executor
		 * handles binding safely (i.e. this method does not perform SQL escaping).
		 *
		 * @param array<string, mixed> $parameters Key-value pairs where keys are column/property names.
		 * @return string
		 */
		private function parametersToString(array $parameters): string {
			$parts = [];
			
			foreach ($parameters as $key => $value) {
				// Produces a condition like "main.id=:id".
				// The ":key" syntax is the named-placeholder convention understood by the
				// ObjectQuel query executor — binding happens downstream, not here.
				$parts[] = "main.{$key}=:{$key}";
			}
			
			// Multiple primary-key columns (composite keys) are ANDed together.
			return implode(" AND ", $parts);
		}

	}