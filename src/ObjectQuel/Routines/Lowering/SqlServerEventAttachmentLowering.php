<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Lowers an attachment to a SQL Server trigger. SQL Server fires one trigger per
	 * statement, not per row, so the generated body loops over `inserted`/`deleted` itself and
	 * EXECs the routine once per changed row — zero times for a zero-row statement. UPDATE
	 * pairs `deleted` and `inserted` on the target's mapped primary key, after first rejecting
	 * a statement that targets any key column and guarding the zero-row case; see "Event
	 * lowering" in objectquel-equel-triggers-design.md.
	 */
	class SqlServerEventAttachmentLowering extends EventAttachmentLowering {

		/** Local cursor name; can't collide with anything since this trigger body has no other cursors */
		private const string CURSOR_NAME = '_eq_pairs';

		/**
		 * @return string
		 */
		protected function engineName(): string {
			return 'SQL Server';
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @return list<string> The CREATE TRIGGER statement
		 * @throws SemanticException|EntityResolutionException
		 */
		public function render(AstEventAttachment $attachment): array {
			$event = $attachment->getEvent();

			if ($event === AttachmentEvent::Replace) {
				$this->assertUsableKey($attachment);
			}

			$triggerName = $this->quoter->quoteIdentifier($this->triggerName($attachment));
			$table = $this->quotedTable($attachment);
			$sqlEvent = $this->sqlEvent($event);
			$body = $event === AttachmentEvent::Replace ? $this->updateBody($attachment) : $this->singleRowsetBody($attachment, $event);

			return ["CREATE TRIGGER {$triggerName}\nON {$table}\nAFTER {$sqlEvent}\nAS\nBEGIN\n\tSET NOCOUNT ON;\n\n{$body}\nEND;"];
		}

		/**
		 * @param string $table Physical table the attachment is on
		 * @param AttachmentEvent $event The attachment's event
		 * @param string $routineName Called routine's name
		 * @return list<string> The DROP TRIGGER statement
		 */
		public function renderDestroy(string $table, AttachmentEvent $event, string $routineName): array {
			$triggerName = $this->quoter->quoteRoutineName(
				EventAttachmentNaming::triggerName($table, $event, $routineName),
				$this->routineSchema
			);

			return ["DROP TRIGGER {$triggerName};"];
		}

		/**
		 * Rejects an UPDATE attachment on a target with no usable key: a v1 restriction, since
		 * `deleted`/`inserted` can only be paired reliably on a non-null, unique mapped key.
		 * @param AstEventAttachment $attachment The attachment
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function assertUsableKey(AstEventAttachment $attachment): void {
			if ($this->targetMetadata($attachment)->identifierColumns === []) {
				throw new SemanticException("'{$attachment->getRange()->getEntityName()}' has no mapped primary key; {$this->engineName()} can't pair deleted/inserted rows for an UPDATE attachment without one.");
			}
		}

		/**
		 * Builds the body for INSERT/DELETE: a single rowset, no pairing, no key required.
		 * @param AstEventAttachment $attachment The attachment
		 * @param AttachmentEvent $event Append or Delete
		 * @return string
		 * @throws EntityResolutionException
		 */
		private function singleRowsetBody(AstEventAttachment $attachment, AttachmentEvent $event): string {
			$alias = $event === AttachmentEvent::Append ? 'i' : 'd';
			$rowset = $event === AttachmentEvent::Append ? 'inserted' : 'deleted';
			$arguments = $this->expandArguments($attachment);
			$select = $this->selectList($arguments, fn(array $arg) => $alias);

			$cursor = "DECLARE " . self::CURSOR_NAME . " CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR\n"
				. "\tSELECT {$select}\n\tFROM {$rowset} AS {$alias};\n";

			return $this->declarations($attachment, $arguments)
				. "\n{$cursor}\n"
				. $this->fetchLoop($attachment, $arguments);
		}

		/**
		 * Builds the body for UPDATE: a zero-row guard, the key-change guard, then `deleted`
		 * paired with `inserted` on every mapped key column.
		 * @param AstEventAttachment $attachment The attachment
		 * @return string
		 * @throws EntityResolutionException
		 */
		private function updateBody(AstEventAttachment $attachment): string {
			$metadata = $this->targetMetadata($attachment);
			$keyChecks = implode(' OR ', array_map(
				fn(string $column) => 'UPDATE(' . $this->quoter->quoteIdentifier($column) . ')',
				$metadata->identifierColumns
			));
			$joinConditions = implode(' AND ', array_map(
				fn(string $column) => 'd.' . $this->quoter->quoteIdentifier($column) . ' = i.' . $this->quoter->quoteIdentifier($column),
				$metadata->identifierColumns
			));

			$guards = "\tIF NOT EXISTS (SELECT 1 FROM inserted)\n\tBEGIN\n\t\tRETURN;\n\tEND;\n\n"
				. "\tIF {$keyChecks}\n\tBEGIN\n"
				. "\t\tRAISERROR('A key column of %s is targeted by this UPDATE; the attached trigger can''t pair changed rows when a key column is written, even to the same value.', 16, 1, '{$this->physicalTable($attachment)}');\n"
				. "\t\tRETURN;\n\tEND;\n";

			$arguments = $this->expandArguments($attachment);
			$select = $this->selectList($arguments, fn(array $arg) => $arg['row'] === 'old' ? 'd' : 'i');

			$cursor = "DECLARE " . self::CURSOR_NAME . " CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR\n"
				. "\tSELECT {$select}\n\tFROM deleted AS d\n\tJOIN inserted AS i ON {$joinConditions};\n";

			return $guards . "\n" . $this->declarations($attachment, $arguments)
				. "\n{$cursor}\n"
				. $this->fetchLoop($attachment, $arguments);
		}

		/**
		 * Builds the cursor's SELECT list, aliasing each expanded argument positionally so the
		 * same column read as both old and new (e.g. `old.username, new.username`) never collides.
		 * @param list<array{row: 'old'|'new', property: string, column: string}> $arguments Expanded arguments
		 * @param callable(array{row: 'old'|'new', property: string, column: string}): string $aliasFor Rowset alias for one argument
		 * @return string
		 */
		private function selectList(array $arguments, callable $aliasFor): string {
			$columns = [];

			foreach ($arguments as $index => $argument) {
				$columns[] = $aliasFor($argument) . '.' . $this->quoter->quoteIdentifier($argument['column']) . ' AS ' . $this->quoter->quoteIdentifier("c{$index}");
			}

			return implode(', ', $columns);
		}

		/**
		 * Declares one local variable per expanded argument; EXEC takes only literals and
		 * variables, so the cursor row is always fetched into locals first.
		 * @param AstEventAttachment $attachment The attachment
		 * @param list<array{row: 'old'|'new', property: string, column: string}> $arguments Expanded arguments
		 * @return string
		 */
		private function declarations(AstEventAttachment $attachment, array $arguments): string {
			$declarations = [];

			foreach ($arguments as $index => $argument) {
				$declarations[] = "@_a{$index} " . $this->columnSqlType($attachment, $argument['property']);
			}

			return "\tDECLARE " . implode(', ', $declarations) . ";\n";
		}

		/**
		 * Builds the OPEN/FETCH/WHILE/EXEC/CLOSE/DEALLOCATE loop, invoking the routine once per row.
		 * @param AstEventAttachment $attachment The attachment
		 * @param list<array{row: 'old'|'new', property: string, column: string}> $arguments Expanded arguments
		 * @return string
		 */
		private function fetchLoop(AstEventAttachment $attachment, array $arguments): string {
			$locals = implode(', ', array_map(fn(int $i) => "@_a{$i}", array_keys($arguments)));
			$routineCall = $this->quoter->quoteRoutineName($attachment->getCall()->getName(), $this->routineSchema);
			$cursor = self::CURSOR_NAME;

			return "\tOPEN {$cursor};\n"
				. "\tFETCH NEXT FROM {$cursor} INTO {$locals};\n\n"
				. "\tWHILE @@FETCH_STATUS = 0\n\tBEGIN\n"
				. "\t\tEXEC {$routineCall} {$locals};\n"
				. "\t\tFETCH NEXT FROM {$cursor} INTO {$locals};\n"
				. "\tEND;\n\n"
				. "\tCLOSE {$cursor};\n"
				. "\tDEALLOCATE {$cursor};\n";
		}
	}
