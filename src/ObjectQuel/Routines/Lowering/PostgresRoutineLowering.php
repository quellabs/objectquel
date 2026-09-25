<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineReferenceSql;

	/**
	 * Lowers an analyzed routine to a PL/pgSQL CREATE FUNCTION (non-void) or
	 * CREATE PROCEDURE (void).
	 *
	 * - Locals and parameter copies live in the top block labelled `_routine`
	 *   and are read as `"_routine"."name"`, so they never resolve as columns.
	 * - A `foreach` whose body writes the current row uses an explicit
	 *   `FOR UPDATE` cursor and `WHERE CURRENT OF`; other loops use `FOR ... IN`.
	 * - `begin transaction { }` commits the work done so far, runs its body and
	 *   commits; `abort` rolls back. Procedures only, as PL/pgSQL requires.
	 */
	class PostgresRoutineLowering extends RoutineLowering {

		/**
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return 'PostgreSQL';
		}

		/**
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> The CREATE OR REPLACE FUNCTION/PROCEDURE statement
		 */
		protected function render(AstRoutineDefinition $routine): array {
			$body = $this->lowerBlock($routine->getBody(), 1);
			$declarations = $this->declarations($routine);
			$declareSection = $declarations === '' ? '' : "DECLARE\n{$declarations}";
			$block = "<<" . RoutineReferenceSql::POSTGRES_BLOCK_LABEL . ">>\n{$declareSection}BEGIN\n{$body}END;";
			$tag = $this->dollarQuoteTag($block);

			return [$this->header($routine) . "\nLANGUAGE plpgsql\nAS {$tag}\n{$block}\n{$tag};"];
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return string `CREATE OR REPLACE FUNCTION name(params) RETURNS type` or the PROCEDURE form
		 */
		private function header(AstRoutineDefinition $routine): string {
			$parameters = [];

			foreach ($routine->getParameters() as $parameter) {
				$parameters[] = $this->quoter->quoteIdentifier($parameter->getName()) . ' ' . $this->sqlType($parameter->getType());
			}

			$signature = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema) . '(' . implode(', ', $parameters) . ')';

			if ($routine->isVoid()) {
				return "CREATE OR REPLACE PROCEDURE {$signature}";
			}

			return "CREATE OR REPLACE FUNCTION {$signature}\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType());
		}

		/**
		 * Declares parameter copies, locals, cursor row records and explicit cursors in the labelled block.
		 * @param AstRoutineDefinition $routine The routine
		 * @return string DECLARE section lines
		 * @throws SemanticException
		 */
		private function declarations(AstRoutineDefinition $routine): string {
			$lines = [];

			// Copies shadow the parameters so every variable is qualified with the same label
			foreach ($routine->getParameters() as $index => $parameter) {
				$lines[] = $this->quoter->quoteIdentifier($parameter->getName()) . ' ' . $this->sqlType($parameter->getType()) . ' := $' . ($index + 1) . ';';
			}

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstDeclare && !$statement->isCursor()) {
					$lines[] = $this->quoter->quoteIdentifier($statement->getName()) . ' ' . $this->sqlType($statement->getType()) . ';';
				}
			}

			foreach (array_keys($this->cursorQueries) as $cursorName) {
				$lines[] = $this->quoter->quoteIdentifier(RoutineReferenceSql::cursorRowVariable($cursorName)) . ' RECORD;';
			}

			// Cursors last: their queries read the variables above when opened
			foreach ($this->cursorQueries as $cursorName => $query) {
				if (isset($this->writeCursors[$cursorName])) {
					$lines[] = $this->quoter->quoteIdentifier($cursorName) . ' CURSOR FOR ' . $this->statements->retrieveSql($query) . ' FOR UPDATE;';
				}
			}

			return $this->lines($lines, 1);
		}

		/**
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `"name" := value;`
		 * @throws SemanticException
		 */
		protected function assignment(string $name, AstInterface $value): string {
			return $this->quoter->quoteIdentifier($name) . ' := ' . $this->assignedValue($name, $value) . ';';
		}

		/**
		 * Closes the explicit cursors of enclosing loops before returning.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			$result = '';

			foreach (array_reverse($this->openExplicitCursors()) as $cursorName) {
				$result .= $this->line('CLOSE ' . $this->quoter->quoteIdentifier($cursorName) . ';', $depth);
			}

			return $result . $this->line('RETURN ' . $this->returnedValue($return) . ';', $depth);
		}

		/**
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerIf(AstIf $if, int $depth): string {
			$result = $this->line('IF ' . $this->statements->compileCondition($if->getCondition()) . ' THEN', $depth);
			$result .= $this->lowerBlock($if->getThenBody(), $depth + 1);

			if ($if->getElseBody() !== null) {
				$result .= $this->line('ELSE', $depth);
				$result .= $this->lowerBlock($if->getElseBody(), $depth + 1);
			}

			return $result . $this->line('END IF;', $depth);
		}

		/**
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerWhile(AstWhile $while, int $depth): string {
			return $this->line('WHILE ' . $this->statements->compileCondition($while->getCondition()) . ' LOOP', $depth)
				. $this->lowerBlock($while->getBody(), $depth + 1)
				. $this->line('END LOOP;', $depth);
		}

		/**
		 * `FOR row IN query LOOP` for read-only loops; OPEN/FETCH/CLOSE when the body writes the current row.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerForeach(AstForeach $foreach, int $depth): string {
			$cursorName = $foreach->getCursorName();
			$row = $this->quoter->quoteIdentifier(RoutineReferenceSql::cursorRowVariable($cursorName));
			$body = $this->lowerLoopBody($foreach, $depth + 1);

			if (!isset($this->writeCursors[$cursorName])) {
				return $this->line("FOR {$row} IN " . $this->statements->retrieveSql($this->cursorQueries[$cursorName]) . ' LOOP', $depth)
					. $body
					. $this->line('END LOOP;', $depth);
			}

			$cursor = $this->quoter->quoteIdentifier($cursorName);

			// A null cursor variable makes OPEN pick a portal name no other routine is using
			return $this->line("{$cursor} := NULL;", $depth)
				. $this->line("OPEN {$cursor};", $depth)
				. $this->line('LOOP', $depth)
				. $this->line("FETCH {$cursor} INTO {$row};", $depth + 1)
				. $this->line('EXIT WHEN NOT FOUND;', $depth + 1)
				. $body
				. $this->line('END LOOP;', $depth)
				. $this->line("CLOSE {$cursor};", $depth);
		}

		/**
		 * @param AstBeginTransaction $transaction The transaction block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException When an enclosing loop uses a cursor COMMIT would close
		 */
		protected function lowerTransaction(AstBeginTransaction $transaction, int $depth): string {
			$explicit = $this->openExplicitCursors();

			// COMMIT closes cursors opened with OPEN; only FOR ... IN loops survive it
			if (!empty($explicit)) {
				throw new SemanticException("'begin transaction' inside 'foreach {$explicit[0]}' isn't possible on PostgreSQL: that loop writes the current row, so it uses a cursor that COMMIT would close.");
			}

			// abort is always last on its path (RoutineControlFlowValidator), so the closing COMMIT only ends the empty transaction ROLLBACK started
			return $this->line('COMMIT;', $depth)
				. $this->lowerBlock($transaction->getBody(), $depth)
				. $this->line('COMMIT;', $depth);
		}

		/**
		 * @return string
		 */
		protected function abortStatement(): string {
			return 'ROLLBACK;';
		}

		/**
		 * An unlabelled EXIT leaves the innermost loop, never the `_routine` block.
		 * @return string
		 */
		protected function breakStatement(): string {
			return 'EXIT;';
		}

		/**
		 * @return string
		 */
		protected function continueStatement(): string {
			return 'CONTINUE;';
		}

		/**
		 * PERFORM runs a query and discards its rows.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return string
		 */
		protected function discardRetrieve(AstRetrieve $retrieve): string {
			$sql = $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve));

			if (!str_starts_with($sql, 'SELECT ')) {
				throw new \LogicException("Expected a SELECT statement, got: {$sql}");
			}

			return 'PERFORM ' . substr($sql, strlen('SELECT ')) . ';';
		}

		/**
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return string `CURRENT OF "cursor"`
		 */
		protected function currentRowCondition(string $cursorName): string {
			return 'CURRENT OF ' . $this->quoter->quoteIdentifier($cursorName);
		}

		/**
		 * @return string[] Enclosing loops that use an explicit (OPENed) cursor, outermost first
		 */
		private function openExplicitCursors(): array {
			return array_values(array_filter($this->openLoops, fn(string $cursorName) => isset($this->writeCursors[$cursorName])));
		}

		/**
		 * Picks a dollar-quote tag that doesn't occur in the body.
		 * @param string $body Function body
		 * @return string Tag such as `$body$`
		 */
		private function dollarQuoteTag(string $body): string {
			$tag = '$body$';

			for ($suffix = 1; str_contains($body, $tag); $suffix++) {
				$tag = "\$body{$suffix}\$";
			}

			return $tag;
		}
	}
