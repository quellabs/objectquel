<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingNaming;

	/**
	 * Lowers a binding to a SQL Server trigger. SQL Server fires one trigger per
	 * statement, not per row, so the generated body loops over `inserted`/`deleted` itself and
	 * EXECs the routine once per changed row — zero times for a zero-row statement. UPDATE
	 * pairs `deleted` and `inserted` on the target's mapped primary key, after first rejecting
	 * a statement that targets any key column and guarding the zero-row case; see "Event
	 * lowering" in objectquel-equel-triggers-design.md.
	 * @phpstan-import-type ExpandedArgument from EventBindingLowering
	 */
	class SqlServerEventBindingLowering extends EventBindingLowering {

		/** Local cursor name; can't collide with anything since this trigger body has no other cursors */
		private const string CURSOR_NAME = '_eq_pairs';

		/**
		 * @return string
		 */
		protected function engineName(): string {
			return 'SQL Server';
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @param string $alias The binding's resolved alias
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<string> The CREATE TRIGGER statement
		 * @throws SemanticException|EntityResolutionException
		 */
		public function render(AstEventBinding $binding, string $alias, int $parameterCount): array {
			$event = $binding->getEvent();

			if ($event === BindingEvent::Replace) {
				$this->assertUsableKey($binding);
			}

			$triggerName = $this->quoter->quoteIdentifier($this->triggerName($binding, $alias));
			$table = $this->quotedTable($binding);
			$sqlEvent = $this->sqlEvent($event);
			$body = $event === BindingEvent::Replace ? $this->updateBody($binding, $parameterCount) : $this->singleRowsetBody($binding, $event, $parameterCount);

			return ["CREATE TRIGGER {$triggerName}\nON {$table}\nAFTER {$sqlEvent}\nAS\nBEGIN\n\tSET NOCOUNT ON;\n\n{$body}\nEND;"];
		}

		/**
		 * @param string $table Physical table the binding is on
		 * @param string $alias The binding's alias
		 * @return list<string> The DROP TRIGGER statement
		 */
		public function renderDestroy(string $table, string $alias): array {
			$triggerName = $this->quoter->quoteRoutineName(
				EventBindingNaming::triggerName($table, $alias),
				$this->routineSchema
			);

			return ["DROP TRIGGER {$triggerName};"];
		}

		/**
		 * Rejects an UPDATE binding on a target with no usable key: a v1 restriction, since
		 * `deleted`/`inserted` can only be paired reliably on a non-null, unique mapped key.
		 * @param AstEventBinding $binding The binding
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function assertUsableKey(AstEventBinding $binding): void {
			if ($this->targetMetadata($binding)->identifierColumns === []) {
				throw new SemanticException("'{$binding->getRange()->getEntityName()}' has no mapped primary key; {$this->engineName()} can't pair deleted/inserted rows for an UPDATE binding without one.");
			}
		}

		/**
		 * Builds the body for INSERT/DELETE: a single rowset, no pairing, no key required.
		 * @param AstEventBinding $binding The binding
		 * @param BindingEvent $event Append or Delete
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return string
		 * @throws EntityResolutionException
		 */
		private function singleRowsetBody(AstEventBinding $binding, BindingEvent $event, int $parameterCount): string {
			$alias = $event === BindingEvent::Append ? 'i' : 'd';
			$rowset = $event === BindingEvent::Append ? 'inserted' : 'deleted';
			$arguments = $this->expandArguments($binding, $parameterCount);
			$select = $this->selectList($arguments, fn(array $arg) => $alias);

			$cursor = "DECLARE " . self::CURSOR_NAME . " CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR\n"
				. "\tSELECT {$select}\n\tFROM {$rowset} AS {$alias};\n";

			return $this->declarations($binding, $arguments)
				. "\n{$cursor}\n"
				. $this->fetchLoop($binding, $arguments);
		}

		/**
		 * Builds the body for UPDATE: a zero-row guard, the key-change guard, then `deleted`
		 * paired with `inserted` on every mapped key column.
		 * @param AstEventBinding $binding The binding
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return string
		 * @throws EntityResolutionException
		 */
		private function updateBody(AstEventBinding $binding, int $parameterCount): string {
			$metadata = $this->targetMetadata($binding);
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
				. "\t\tRAISERROR('A key column of %s is targeted by this UPDATE; the bound trigger can''t pair changed rows when a key column is written, even to the same value.', 16, 1, '{$this->physicalTable($binding)}');\n"
				. "\t\tRETURN;\n\tEND;\n";

			$arguments = $this->expandArguments($binding, $parameterCount);
			$select = $this->selectList($arguments, fn(array $arg) => $arg['row'] === 'old' ? 'd' : 'i');

			$cursor = "DECLARE " . self::CURSOR_NAME . " CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR\n"
				. "\tSELECT {$select}\n\tFROM deleted AS d\n\tJOIN inserted AS i ON {$joinConditions};\n";

			return $guards . "\n" . $this->declarations($binding, $arguments)
				. "\n{$cursor}\n"
				. $this->fetchLoop($binding, $arguments);
		}

		/**
		 * Builds the cursor's SELECT list, aliasing each expanded argument positionally so the
		 * same column read as both old and new (e.g. `old.username, new.username`) never collides.
		 * @param list<ExpandedArgument> $arguments Expanded arguments
		 * @param callable(ExpandedArgument): string $aliasFor Rowset alias for one argument
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
		 * @param AstEventBinding $binding The binding
		 * @param list<ExpandedArgument> $arguments Expanded arguments
		 * @return string
		 */
		private function declarations(AstEventBinding $binding, array $arguments): string {
			$declarations = [];

			foreach ($arguments as $index => $argument) {
				$declarations[] = "@_a{$index} " . $this->columnSqlType($binding, $argument['property']);
			}

			return "\tDECLARE " . implode(', ', $declarations) . ";\n";
		}

		/**
		 * Builds the OPEN/FETCH/WHILE/EXEC/CLOSE/DEALLOCATE loop, invoking the routine once per row.
		 * @param AstEventBinding $binding The binding
		 * @param list<ExpandedArgument> $arguments Expanded arguments
		 * @return string
		 */
		private function fetchLoop(AstEventBinding $binding, array $arguments): string {
			$locals = implode(', ', array_map(fn(int $i) => "@_a{$i}", array_keys($arguments)));
			$routineCall = $this->quoter->quoteRoutineName($binding->getRoutineName(), $this->routineSchema);
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
