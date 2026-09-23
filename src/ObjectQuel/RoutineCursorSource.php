<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\FindPropertyRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Finds the one entity table a current-row `delete x`/`replace x` writes to.
	 * v1 only allows this when the cursor reads a single, unjoined entity range
	 * with no `unique` and no aggregates, so every row maps to one table row.
	 */
	class RoutineCursorSource extends FindPropertyRange {

		private EntityStore $entityStore;
		private RoutineRangeReferences $rangeReferences;

		/**
		 * @param EntityStore $entityStore Entity metadata for property lookups
		 */
		public function __construct(EntityStore $entityStore) {
			parent::__construct($entityStore);
			$this->entityStore = $entityStore;
			$this->rangeReferences = new RoutineRangeReferences($entityStore);
		}

		/**
		 * @param string $cursorName Cursor the write names, for error messages
		 * @param AstRetrieve $query The cursor's query
		 * @param AstRange[] $ranges Ranges declared before the cursor
		 * @return AstRangeDatabase The range whose table the write targets
		 * @throws SemanticException When the cursor can't take a current-row write
		 * @throws EntityResolutionException
		 */
		public function resolve(string $cursorName, AstRetrieve $query, array $ranges): AstRangeDatabase {
			if ($query->isUnique()) {
				throw new SemanticException("Cursor '{$cursorName}' uses 'retrieve unique', so its rows don't map to single table rows and can't be deleted or replaced.");
			}

			$aggregates = new CollectNodes(AstAggregate::class);
			$query->acceptWithoutRanges($aggregates);

			if (!empty($aggregates->getCollectedNodes())) {
				throw new SemanticException("Cursor '{$cursorName}' uses an aggregate, so its rows don't map to single table rows and can't be deleted or replaced.");
			}

			$referenced = $this->collectReferencedRanges($cursorName, $query, $ranges);

			if (count($referenced) !== 1) {
				throw new SemanticException("Cursor '{$cursorName}' reads more than one range, so it's ambiguous which table 'delete {$cursorName}'/'replace {$cursorName}' writes to.");
			}

			$source = reset($referenced);

			if (!$source instanceof AstRangeDatabase || $source->getJoinProperty() !== null || $source->getViaRelation() !== null) {
				throw new SemanticException("Cursor '{$cursorName}' must read a plain entity range (no joins) to allow 'delete {$cursorName}'/'replace {$cursorName}'.");
			}

			return $source;
		}

		/**
		 * Collects the ranges the query's target list and `where` read, rejecting related-entity reads.
		 * @param string $cursorName Cursor name, for error messages
		 * @param AstRetrieve $query The cursor's query
		 * @param AstRange[] $ranges Ranges declared before the cursor
		 * @return array<string, AstRange> Referenced ranges keyed by name
		 * @throws SemanticException When the query reads a related entity
		 * @throws EntityResolutionException
		 */
		private function collectReferencedRanges(string $cursorName, AstRetrieve $query, array $ranges): array {
			$referenced = $this->rangeReferences->direct($query, $ranges);

			$identifiers = new CollectNodes(AstIdentifier::class);
			$query->acceptWithoutRanges($identifiers);

			foreach ($identifiers->getCollectedNodes() as $identifier) {
				$range = $referenced[$identifier->getName()] ?? null;

				if ($range !== null && !$identifier->getParent() instanceof AstIdentifier) {
					$this->assertNotRelationRead($cursorName, $identifier, $range);
				}
			}

			return $referenced;
		}

		/**
		 * Rejects `u.relation...`, which the query pipeline turns into a join.
		 * @param string $cursorName Cursor name, for error messages
		 * @param AstIdentifier $identifier Root identifier naming $range
		 * @param AstRange $range The range it names
		 * @return void
		 * @throws SemanticException
		 * @throws EntityResolutionException
		 */
		private function assertNotRelationRead(string $cursorName, AstIdentifier $identifier, AstRange $range): void {
			$property = $identifier->getNext();

			if ($property === null || !$range instanceof AstRangeDatabase) {
				return;
			}

			// Columns (and JSON paths into them) stay on the range's own table
			if (isset($this->entityStore->getMetadata($range->getEntityName())->columnMap[$property->getName()])) {
				return;
			}

			if (!empty($this->findRanges($property->getName(), [$range]))) {
				throw new SemanticException("Cursor '{$cursorName}' reads related entity '{$identifier->getCompleteName()}', which joins another table, so it can't take a current-row write.");
			}
		}
	}
