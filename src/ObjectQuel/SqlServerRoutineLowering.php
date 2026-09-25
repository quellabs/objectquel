<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;

	/**
	 * Lowers an analyzed routine to a T-SQL CREATE OR ALTER FUNCTION (non-void)
	 * or PROCEDURE (void).
	 *
	 * - Each `foreach` declares a LOCAL cursor when the loop starts, because T-SQL
	 *   reads the variables in a cursor query at DECLARE; the loop deallocates it.
	 *   Read-only loops use a STATIC cursor, loops that write the current row a
	 *   SCROLL_LOCKS `FOR UPDATE` cursor and `WHERE CURRENT OF`.
	 * - A function can't write tables or run a procedure, so a non-void routine that does is rejected.
	 */
	class SqlServerRoutineLowering extends FetchIntoRoutineLowering {

		/** Scratch variable that makes an otherwise empty block a valid statement list */
		private const string NOOP_VARIABLE = '@_noop';

		/** Scratch variable a function uses to count rows from a discarded retrieve */
		private const string DISCARD_VARIABLE = '@_discard';

		private bool $usesNoopVariable;
		private bool $isFunction;
		private int $discardCursorCount;

		/**
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return 'SQL Server';
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException When a function writes tables or calls a procedure
		 */
		protected function validate(AstRoutineDefinition $routine): void {
			parent::validate($routine);
			$this->usesNoopVariable = false;
			$this->isFunction = !$routine->isVoid();
			$this->discardCursorCount = 0;

			if (!$routine->isVoid() && $this->writesTables($routine)) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so SQL Server creates it as a FUNCTION, which can't write tables. Make it void to write.");
			}

			if (!$routine->isVoid() && $this->contains($routine, [AstCall::class])) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so SQL Server creates it as a FUNCTION, which can't run a procedure. Make it void to call a procedure as a statement.");
			}
		}

		/**
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> The CREATE OR ALTER FUNCTION/PROCEDURE statement
		 */
		protected function render(AstRoutineDefinition $routine): array {
			$body = $this->lowerBlock($routine->getBody(), 1);
			$statements = $routine->getBody();

			// SQL Server requires a function body to end in RETURN, even when every path already returns
			if (!$routine->isVoid() && !end($statements) instanceof AstReturn) {
				$body .= $this->line('RETURN NULL;', 1);
			}

			$parameters = $this->parameterVariables($routine);
			$locals = $this->localVariables($routine) + $this->fieldVariableTypes();

			if ($this->usesNoopVariable) {
				$locals[self::NOOP_VARIABLE] = 'BIT';
			}

			if ($this->usesDiscardVariable) {
				$locals[self::DISCARD_VARIABLE] = 'INT';
			}

			$declarations = '';

			foreach ($locals as $name => $type) {
				$declarations .= $this->line("DECLARE {$name} {$type};", 1);
			}

			return [$this->header($routine, $parameters) . "\nAS\nBEGIN\n{$declarations}{$body}END;"];
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @param array<string, string> $parameters SQL type of each parameter, by variable name
		 * @return string
		 */
		private function header(AstRoutineDefinition $routine, array $parameters): string {
			$list = implode(', ', array_map(fn(string $name, string $type) => "{$name} {$type}", array_keys($parameters), $parameters));
			$name = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema);

			if ($routine->isVoid()) {
				return "CREATE OR ALTER PROCEDURE {$name}" . ($list === '' ? '' : " {$list}");
			}

			return "CREATE OR ALTER FUNCTION {$name}({$list})\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType());
		}

		/**
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `SET @name = value;`
		 * @throws SemanticException
		 */
		protected function assignment(string $name, AstInterface $value): string {
			return 'SET ' . $this->variableName($name) . ' = ' . $this->assignedValue($name, $value) . ';';
		}

		/**
		 * Releases the cursors of enclosing loops before returning.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			$result = '';

			foreach (array_reverse($this->openLoops) as $cursorName) {
				$result .= $this->lines(['CLOSE ' . $this->cursorName($cursorName) . ';', 'DEALLOCATE ' . $this->cursorName($cursorName) . ';'], $depth);
			}

			return $result . $this->line('RETURN ' . $this->returnedValue($return) . ';', $depth);
		}

		/**
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerIf(AstIf $if, int $depth): string {
			$elseBody = $if->getElseBody();
			$result = $this->line('IF ' . $this->statements->compileCondition($if->getCondition()), $depth)
				. $this->block($this->lowerBlock($if->getThenBody(), $depth + 1), $depth, $elseBody === null);

			if ($elseBody !== null) {
				$result .= $this->line('ELSE', $depth) . $this->block($this->lowerBlock($elseBody, $depth + 1), $depth, true);
			}

			return $result;
		}

		/**
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerWhile(AstWhile $while, int $depth): string {
			return $this->line('WHILE ' . $this->statements->compileCondition($while->getCondition()), $depth)
				. $this->block($this->lowerBlock($while->getBody(), $depth + 1), $depth, true);
		}

		/**
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerForeach(AstForeach $foreach, int $depth): string {
			$cursorName = $foreach->getCursorName();
			$cursor = $this->cursorName($cursorName);
			$select = $this->statements->retrieveSql($this->cursorQueries[$cursorName]);

			$declaration = isset($this->writeCursors[$cursorName])
				? "DECLARE {$cursor} CURSOR LOCAL FORWARD_ONLY DYNAMIC SCROLL_LOCKS FOR {$select} FOR UPDATE;"
				: "DECLARE {$cursor} CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR {$select};";

			$fetch = $this->lines([
				"FETCH NEXT FROM {$cursor} INTO " . implode(', ', $this->fieldVariables($cursorName)) . ';',
				'IF @@FETCH_STATUS <> 0 BREAK;',
			], $depth + 1);

			return $this->lines([$declaration, "OPEN {$cursor};", 'WHILE 1 = 1', 'BEGIN'], $depth)
				. $fetch
				. $this->lowerLoopBody($foreach, $depth + 1)
				. $this->lines(['END;', "CLOSE {$cursor};", "DEALLOCATE {$cursor};"], $depth);
		}

		/**
		 * The closing COMMIT is skipped after `abort`, whose ROLLBACK already ended the transaction.
		 * @param AstBeginTransaction $transaction The transaction block
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerTransactionBlock(AstBeginTransaction $transaction, int $depth): string {
			return $this->line('BEGIN TRANSACTION;', $depth)
				. $this->lowerBlock($transaction->getBody(), $depth)
				. $this->line('IF @@TRANCOUNT > 0 COMMIT TRANSACTION;', $depth);
		}

		/**
		 * @return string
		 */
		protected function abortStatement(): string {
			return 'ROLLBACK TRANSACTION;';
		}

		/**
		 * @return string
		 */
		protected function breakStatement(): string {
			return 'BREAK;';
		}

		/**
		 * Jumps to `WHILE`; a cursor loop fetches its next row at the top of the body.
		 * @return string
		 */
		protected function continueStatement(): string {
			return 'CONTINUE;';
		}

		/**
		 * @param string $derivedTable Parenthesized SELECT
		 * @return string
		 */
		protected function countInto(string $derivedTable): string {
			return 'SELECT ' . self::DISCARD_VARIABLE . " = COUNT(*) FROM {$derivedTable} AS " . $this->quoter->quoteIdentifier('_discard') . ';';
		}

		/**
		 * Executes the complete retrieve while discarding each fetched row.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return string T-SQL cursor statements
		 */
		protected function discardRetrieve(AstRetrieve $retrieve): string {
			$sql = $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve));

			if ($this->isFunction) {
				$this->usesDiscardVariable = true;

				if (!empty($retrieve->getSort())) {
					$sql .= ' OFFSET 0 ROWS';
				}

				return $this->countInto('(' . $sql . ')');
			}

			$cursor = '_discard_' . ++$this->discardCursorCount;

			return "DECLARE {$cursor} CURSOR LOCAL FAST_FORWARD FOR {$sql}; "
				. "OPEN {$cursor}; FETCH NEXT FROM {$cursor}; "
				. "WHILE @@FETCH_STATUS = 0 FETCH NEXT FROM {$cursor}; "
				. "CLOSE {$cursor}; DEALLOCATE {$cursor};";
		}

		/**
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return string `CURRENT OF cursor`
		 */
		protected function currentRowCondition(string $cursorName): string {
			return 'CURRENT OF ' . $this->cursorName($cursorName);
		}

		/**
		 * Wraps lowered statements in BEGIN ... END; T-SQL rejects an empty block, so one gets a no-op assignment.
		 * @param string $statements Lowered statements, indented one level deeper than $depth
		 * @param int $depth Indentation depth of BEGIN/END
		 * @param bool $terminate True to end the enclosing statement after END; false when ELSE follows
		 * @return string
		 */
		private function block(string $statements, int $depth, bool $terminate): string {
			if ($statements === '') {
				$this->usesNoopVariable = true;
				$statements = $this->line('SET ' . self::NOOP_VARIABLE . ' = 0;', $depth + 1);
			}

			return $this->line('BEGIN', $depth) . $statements . $this->line($terminate ? 'END;' : 'END', $depth);
		}
	}
