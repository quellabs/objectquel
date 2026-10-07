<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;

	/**
	 * Validates an attachment against the called routine's live metadata: its deployed
	 * `trigger` return type, entity-row parameter count/type against the event's own row
	 * count, and the transitive safety graph of everything it calls. This needs a live
	 * connection, so it runs at attachment time rather than at parse time. See "Routine
	 * metadata and attachment dependencies" in objectquel-equel-triggers-design.md.
	 */
	class EventAttachmentValidator {

		/**
		 * @param DatabaseAdapter $connection Connection whose routine catalog is read
		 * @param EntityStore $entityStore Entity metadata, to resolve the attachment's target entity
		 */
		public function __construct(
			private readonly DatabaseAdapter $connection,
			private readonly EntityStore $entityStore,
		) {
		}

		/**
		 * Validates the attachment, rejecting before any DDL is emitted.
		 * @param AstEventAttachment $attachment Parsed, analyzer-checked attachment
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function validate(AstEventAttachment $attachment): void {
			$routineName = $attachment->getRoutineName();
			$metadata = $this->connection->getRoutineMetadata($routineName);

			if (($metadata['returnType'] ?? null) !== 'trigger') {
				throw new QuelException("Can't attach '{$routineName}': it isn't declared 'trigger'.", 'routine_call_error');
			}

			$this->checkParameters($attachment, $metadata);

			$triggeringTable = $this->entityStore->getMetadata($attachment->getRange()->getEntityName())->tableName;
			$this->checkCallGraph($routineName, $metadata, $triggeringTable, []);
		}

		/**
		 * Matches the routine's declared entity-row parameters, by position, against the event's
		 * own row count and the attachment's target entity. The attachment has no argument list
		 * of its own — the event determines how many rows are available and in what order (see
		 * AttachmentEvent::rowRoles()), and the routine's parameter list must match exactly.
		 * @param AstEventAttachment $attachment The attachment
		 * @param array<string, mixed> $metadata The called routine's metadata
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		private function checkParameters(AstEventAttachment $attachment, array $metadata): void {
			$routineName = $attachment->getRoutineName();
			$rowCount = count($attachment->getEvent()->rowRoles());
			$parameters = self::asArray($metadata['parameters'] ?? null);

			if (count($parameters) !== $rowCount) {
				throw new QuelException("Can't attach '{$routineName}': it declares " . count($parameters) . " entity-row parameter(s), but '{$attachment->getEvent()->keyword()}' supplies {$rowCount}.", 'routine_call_error');
			}

			$targetEntityClass = $this->entityStore->getMetadata($attachment->getRange()->getEntityName())->className;

			foreach ($parameters as $index => $parameter) {
				$parameter = self::asArray($parameter);
				$position = $index + 1;

				if (self::asStringOrNull($parameter['kind'] ?? null) !== 'entity') {
					throw new QuelException("Can't attach '{$routineName}': parameter {$position} isn't an entity-row parameter.", 'routine_call_error');
				}

				$declaredType = self::asStringOrNull($parameter['type'] ?? null);

				if ($declaredType !== $targetEntityClass) {
					throw new QuelException("Can't attach '{$routineName}': parameter {$position} is typed '" . ($declaredType ?? 'unknown') . "', but the attachment's target is '{$targetEntityClass}'.", 'routine_call_error');
				}
			}
		}

		/**
		 * Walks the called routine's transitive call graph, rejecting a write to the triggering
		 * table anywhere reachable. Cycle-safe: a name already walked on this path is not walked
		 * again.
		 * @param string $routineName Routine being checked
		 * @param array<string, mixed> $metadata Its metadata
		 * @param string $triggeringTable Physical table the attachment is on
		 * @param array<string, true> $visited Lowercased routine names already walked on this path
		 * @return void
		 * @throws QuelException
		 */
		private function checkCallGraph(string $routineName, array $metadata, string $triggeringTable, array $visited): void {
			$key = strtolower($routineName);

			if (isset($visited[$key])) {
				return;
			}

			$visited[$key] = true;
			$safety = self::asArray($metadata['safety'] ?? null);

			foreach (self::asStringList($safety['writes'] ?? null) as $table) {
				if (strcasecmp($table, $triggeringTable) === 0) {
					throw new QuelException("Can't attach: '{$routineName}' writes '{$table}', the table the attachment fires on; a trigger can't write its own triggering table.", 'routine_call_error');
				}
			}

			foreach (self::asStringList($safety['calls'] ?? null) as $calledName) {
				$calledMetadata = $this->connection->getRoutineMetadata($calledName);
				$this->checkCallGraph($calledName, $calledMetadata, $triggeringTable, $visited);
			}
		}

		/**
		 * Narrows a decoded-JSON value to an array. The metadata document's own shape is
		 * trusted (RoutineMetadata), but PHP's `array<string, mixed>` can't express that a
		 * particular field is itself array-shaped, so a nested access always starts as `mixed`.
		 * @param mixed $value
		 * @return array<mixed, mixed>
		 */
		private static function asArray(mixed $value): array {
			return is_array($value) ? $value : [];
		}

		/**
		 * Narrows a decoded-JSON value to a list of strings, for a `safety.calls`/
		 * `safety.writes` field. A non-string entry is dropped rather than tripping the walk.
		 * @param mixed $value
		 * @return list<string>
		 */
		private static function asStringList(mixed $value): array {
			if (!is_array($value)) {
				return [];
			}

			return array_values(array_filter($value, 'is_string'));
		}

		/**
		 * Narrows a decoded-JSON value to a string, or null when it isn't one.
		 * @param mixed $value
		 * @return string|null
		 */
		private static function asStringOrNull(mixed $value): ?string {
			return is_string($value) ? $value : null;
		}
	}
