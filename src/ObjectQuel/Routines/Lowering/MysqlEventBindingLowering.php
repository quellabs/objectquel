<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingNaming;

	/**
	 * Lowers a binding to a MySQL/MariaDB trigger that calls the routine directly.
	 * `CALL routine(args);` is itself a single valid statement, so the trigger body needs no
	 * BEGIN/END wrapper.
	 */
	class MysqlEventBindingLowering extends EventBindingLowering {

		/**
		 * @return string
		 */
		protected function engineName(): string {
			return $this->platform->getDatabaseType() === 'mariadb' ? 'MariaDB' : 'MySQL';
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @param string $alias The binding's resolved alias
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<string> The CREATE TRIGGER statement
		 * @throws EntityResolutionException
		 */
		public function render(AstEventBinding $binding, string $alias, int $parameterCount): array {
			$triggerName = $this->quoter->quoteIdentifier($this->triggerName($binding, $alias));
			$table = $this->quotedTable($binding);
			$event = $this->sqlEvent($binding->getEvent());
			$routineCall = $this->quoter->quoteRoutineName($binding->getRoutineName(), null);

			$arguments = implode(', ', array_map(
				fn(array $arg) => strtoupper($arg['row']) . '.' . $this->quoter->quoteIdentifier($arg['column']),
				$this->expandArguments($binding, $parameterCount)
			));

			return ["CREATE TRIGGER {$triggerName}\nAFTER {$event} ON {$table}\nFOR EACH ROW\nCALL {$routineCall}({$arguments});"];
		}

		/**
		 * @param string $table Physical table the binding is on
		 * @param string $alias The binding's alias
		 * @return list<string> The DROP TRIGGER statement
		 */
		public function renderDestroy(string $table, string $alias): array {
			$triggerName = $this->quoter->quoteIdentifier(EventBindingNaming::triggerName($table, $alias));
			return ["DROP TRIGGER {$triggerName};"];
		}
	}
