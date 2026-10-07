<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;

	/**
	 * Validates an attachment against the called routine's live metadata: its deployed
	 * `trigger` return type, row/scalar parameter shape, and the transitive safety graph of
	 * everything it calls — the checks EventAttachmentAnalyzer can't do without the database.
	 * See "Routine metadata and attachment dependencies" in objectquel-equel-triggers-design.md.
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
			$routineName = $attachment->getCall()->getName();
			$metadata = $this->connection->getRoutineMetadata($routineName);

			if (($metadata['returnType'] ?? null) !== 'trigger') {
				throw new QuelException("Can't attach '{$routineName}': it isn't declared 'trigger'.", 'routine_call_error');
			}

			$this->checkArguments($attachment, $metadata);

			$triggeringTable = $this->entityStore->getMetadata($attachment->getRange()->getEntityName())->tableName;
			$this->checkCallGraph($routineName, $metadata, $triggeringTable, []);
		}

		/**
		 * Matches each call argument, by position, against the routine's declared parameter kind,
		 * and a whole-row argument's entity against the attachment's target entity.
		 * @param AstEventAttachment $attachment The attachment
		 * @param array<string, mixed> $metadata The called routine's metadata
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		private function checkArguments(AstEventAttachment $attachment, array $metadata): void {
			$routineName = $attachment->getCall()->getName();
			$arguments = $attachment->getCall()->getArguments();
			$parameters = $metadata['parameters'] ?? [];

			if (count($arguments) !== count($parameters)) {
				throw new QuelException("Can't attach '{$routineName}': it takes " . count($parameters) . ' parameter(s), but the attachment passes ' . count($arguments) . '.', 'routine_call_error');
			}

			$targetEntityClass = $this->entityStore->getMetadata($attachment->getRange()->getEntityName())->className;

			foreach ($arguments as $index => $argument) {
				$isWholeRow = $argument instanceof AstIdentifier && $argument->getNext() === null;
				$parameterKind = $parameters[$index]['kind'] ?? null;
				$position = $index + 1;

				if ($isWholeRow && $parameterKind !== 'entity') {
					throw new QuelException("Can't attach '{$routineName}': parameter {$position} isn't a row parameter, but the attachment passes the whole row.", 'routine_call_error');
				}

				if (!$isWholeRow && $parameterKind === 'entity') {
					throw new QuelException("Can't attach '{$routineName}': parameter {$position} is a row parameter, but the attachment doesn't pass the whole row.", 'routine_call_error');
				}

				if ($isWholeRow && ($parameters[$index]['type'] ?? null) !== $targetEntityClass) {
					$declaredType = $parameters[$index]['type'] ?? 'unknown';
					throw new QuelException("Can't attach '{$routineName}': parameter {$position} is typed '{$declaredType}', but the attachment's target is '{$targetEntityClass}'.", 'routine_call_error');
				}
			}
		}

		/**
		 * Walks the called routine's transitive call graph, rejecting a write to the triggering
		 * table and an unsafe feature anywhere reachable. Cycle-safe: a name already walked on
		 * this path is not walked again.
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
			$safety = $metadata['safety'] ?? [];

			if (!empty($safety['features'])) {
				throw new QuelException("Can't attach: '{$routineName}' uses '{$safety['features'][0]}', which isn't safe to run inside a trigger.", 'routine_call_error');
			}

			foreach ($safety['writes'] ?? [] as $table) {
				if (strcasecmp($table, $triggeringTable) === 0) {
					throw new QuelException("Can't attach: '{$routineName}' writes '{$table}', the table the attachment fires on; a trigger can't write its own triggering table.", 'routine_call_error');
				}
			}

			foreach ($safety['calls'] ?? [] as $calledName) {
				$calledMetadata = $this->connection->getRoutineMetadata($calledName);
				$this->checkCallGraph($calledName, $calledMetadata, $triggeringTable, $visited);
			}
		}
	}
