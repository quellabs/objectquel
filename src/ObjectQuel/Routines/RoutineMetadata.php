<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Builds the versioned JSON metadata a deployed routine carries, so its source-level
	 * row-parameter typing and direct safety facts survive separate catalog queries — see
	 * "Routine metadata and binding dependencies" in objectquel-equel-triggers-design.md.
	 * Read back per dialect by RoutineDefinitionInspector.
	 */
	class RoutineMetadata {

		/** Metadata format version, bumped on an incompatible shape change */
		private const int VERSION = 1;

		/**
		 * Builds the metadata JSON for an analyzed routine.
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @param EntityStore $entityStore Entity metadata
		 * @return string JSON metadata, as stored in the routine's native comment/extended property
		 * @throws EntityResolutionException
		 */
		public static function build(AstRoutineDefinition $routine, EntityStore $entityStore): string {
			$data = [
				'objectQuel' => self::VERSION,
				'isTrigger'  => $routine->isTrigger(),
				'returnType' => self::returnType($routine),
				'atomic'     => self::containsAtomic($routine),
				'parameters' => self::parameters($routine, $entityStore),
				'safety'     => self::safety($routine, $entityStore),
			];

			$json = json_encode($data, JSON_UNESCAPED_SLASHES);

			if ($json === false) {
				throw new \LogicException("Failed to encode metadata for routine '{$routine->getName()}': " . json_last_error_msg());
			}

			return $json;
		}

		/**
		 * A tfunction's declared return type is already 'void' (RoutineDefinition); whether it's
		 * a trigger is carried separately by the `isTrigger` key.
		 * @param AstRoutineDefinition $routine The routine
		 * @return string 'void', or the normalized scalar return type
		 */
		private static function returnType(AstRoutineDefinition $routine): string {
			if ($routine->isVoid()) {
				return 'void';
			}

			return RoutineAnalyzer::normalizeType($routine->getDeclaredReturnType());
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return bool True when the body contains an `atomic` block
		 */
		private static function containsAtomic(AstRoutineDefinition $routine): bool {
			$collector = new CollectNodes(AstAtomic::class);
			$routine->accept($collector);
			return !empty($collector->getCollectedNodes());
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @param EntityStore $entityStore Entity metadata
		 * @return list<array{kind: string, type: string}> Ordered source parameter kinds and types
		 * @throws EntityResolutionException
		 */
		private static function parameters(AstRoutineDefinition $routine, EntityStore $entityStore): array {
			$parameters = [];

			foreach ($routine->getParameters() as $parameter) {
				$entityClass = RoutineAnalyzer::resolveEntityType($entityStore, $parameter->getType());

				$parameters[] = $entityClass !== null
					? ['kind' => 'entity', 'type' => $entityClass]
					: ['kind' => 'scalar', 'type' => RoutineAnalyzer::normalizeType($parameter->getType())];
			}

			return $parameters;
		}

		/**
		 * Direct (non-transitive) safety facts about this routine's own body. The transitive
		 * call graph is walked by the binding/destroy checks that consume this, by reading
		 * each reachable routine's own metadata in turn.
		 * @param AstRoutineDefinition $routine The routine
		 * @param EntityStore $entityStore Entity metadata
		 * @return array{calls: list<string>, reads: list<string>, writes: list<string>}
		 * @throws EntityResolutionException
		 */
		private static function safety(AstRoutineDefinition $routine, EntityStore $entityStore): array {
			$writes = self::writtenTableNames($routine, $entityStore);
			$reads = array_values(array_diff(self::declaredRangeTableNames($routine, $entityStore), $writes));

			return [
				'calls'  => self::calledRoutineNames($routine),
				'reads'  => $reads,
				'writes' => $writes,
			];
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return list<string> Distinct routine names this routine calls, as written
		 */
		private static function calledRoutineNames(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes(AstRoutineCall::class);
			$routine->accept($collector);

			$names = [];

			foreach ($collector->getCollectedNodes() as $call) {
				$names[strtolower($call->getName())] = $call->getName();
			}

			return array_values($names);
		}

		/**
		 * Every declared range's physical table, read or not — a harmless over-approximation
		 * for `reads`, since nothing here rejects an unnecessarily declared range.
		 * @param AstRoutineDefinition $routine The routine
		 * @param EntityStore $entityStore Entity metadata
		 * @return list<string> Distinct physical table names
		 * @throws EntityResolutionException
		 */
		private static function declaredRangeTableNames(AstRoutineDefinition $routine, EntityStore $entityStore): array {
			$tables = [];

			foreach ($routine->getRanges() as $range) {
				if ($range instanceof AstRangeDatabase) {
					$tables[$entityStore->getMetadata($range->getEntityName())->tableName] = true;
				}
			}

			return array_keys($tables);
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @param EntityStore $entityStore Entity metadata
		 * @return list<string> Distinct physical tables targeted by append/replace/delete
		 * @throws EntityResolutionException
		 */
		private static function writtenTableNames(AstRoutineDefinition $routine, EntityStore $entityStore): array {
			$collector = new CollectNodes([AstAppend::class, AstReplace::class, AstDelete::class]);
			$routine->accept($collector);

			$tables = [];

			foreach ($collector->getCollectedNodes() as $statement) {
				$entityName = match (true) {
					$statement instanceof AstAppend => $statement->getEntityName(),
					$statement instanceof AstReplace, $statement instanceof AstDelete => $statement->getRange()->getEntityName(),
				};

				if ($entityName !== null) {
					$tables[$entityStore->getMetadata($entityName)->tableName] = true;
				}
			}

			return array_keys($tables);
		}
	}
