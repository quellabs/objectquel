<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;

	/**
	 * Lowers an analyzed routine to a T-SQL CREATE FUNCTION (non-void)
	 * or PROCEDURE (void).
	 *
	 * - Each `foreach` declares a LOCAL STATIC READ_ONLY cursor when the loop
	 *   starts, because T-SQL reads the variables in a cursor query at DECLARE;
	 *   the loop deallocates it. A `replace`/`delete` naming a table inside it
	 *   writes it the ordinary way, with its own explicit `where`, same as
	 *   anywhere else in the routine.
	 * - A function can't write tables or run a procedure, so a non-void routine that does is rejected.
	 * - EXEC takes only literals and variables, so any other procedure argument is first stored in a local.
	 * @phpstan-import-type LoopFrame from RoutineLowering
	 */
	class SqlServerRoutineLowering extends FetchIntoRoutineLowering {

		/** Scratch variable that makes an otherwise empty block a valid statement list */
		private const string NOOP_VARIABLE = '@_noop';

		/** Scratch variable a function uses to count rows from a discarded retrieve */
		private const string DISCARD_VARIABLE = '@_discard';

		/** @var array<string, string> SQL type of each local holding a procedure argument, by variable name */
		private array $argumentVariables;

		private bool $usesNoopVariable;
		private bool $isFunction;
		private int $discardCursorCount;
		private int $atomicBlockCount;
		
		/** @var array<string, string> Scratch variables used by atomic blocks */
		private array $atomicVariables;
		private string $atomicOwnerVariable;
		private string $atomicSavepointVariable;

		/**
		 * Returns the target engine name.
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return 'SQL Server';
		}

		/**
		 * Checks routine constructs unsupported by the target engine.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException When a function writes tables or calls a procedure
		 */
		protected function validate(AstRoutineDefinition $routine): void {
			parent::validate($routine);
			$this->usesNoopVariable = false;
			$this->argumentVariables = [];
			$this->isFunction = !$routine->returnsNoValue();
			$this->discardCursorCount = 0;
			$this->atomicBlockCount = 0;
			$this->atomicVariables = [];

			if (!$routine->returnsNoValue() && $this->writesTables($routine)) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so SQL Server creates it as a FUNCTION, which can't write tables. Make it void to write.");
			}

			if (!$routine->returnsNoValue() && $this->contains($routine, [AstCall::class])) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so SQL Server creates it as a FUNCTION, which can't run a procedure. Make it void to call a procedure as a statement.");
			}
		}
		
		/**
		 * Renders the routine definition as SQL.
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> The CREATE FUNCTION/PROCEDURE statement
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function render(AstRoutineDefinition $routine): array {
			$body = $this->lowerBlock($routine->getBody(), 1);
			$statements = $routine->getBody();

			// SQL Server requires a function body to end in RETURN, even when every path already returns
			if (!$routine->returnsNoValue() && !end($statements) instanceof AstReturn) {
				$body .= $this->line('RETURN NULL;', 1);
			}

			$parameters = $this->parameterVariables($routine);
			$locals = $this->localVariables($routine) + $this->fieldVariableTypes() + $this->argumentVariables + $this->atomicVariables;

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

			$create = $this->header($routine, $parameters) . "\nAS\nBEGIN\n{$declarations}{$body}END;";

			return [$create, $this->metadataPropertyStatement($routine)];
		}

		/**
		 * Attaches the routine's JSON metadata as a named extended property, read back through
		 * sys.extended_properties (see RoutineDefinitionInspector).
		 * @param AstRoutineDefinition $routine The routine
		 * @return string `sys.sp_addextendedproperty` call
		 */
		private function metadataPropertyStatement(AstRoutineDefinition $routine): string {
			$schema = $this->routineSchema ?? 'dbo';
			$name = $this->quoter->escapeStringLiteral($routine->getName());
			$metadata = $this->quoter->escapeStringLiteral($this->metadataJson);
			$level1Type = $routine->returnsNoValue() ? 'PROCEDURE' : 'FUNCTION';

			return "EXEC sys.sp_addextendedproperty "
				. "@name = N'ObjectQuel_Metadata', @value = N'{$metadata}', "
				. "@level0type = N'SCHEMA', @level0name = N'{$schema}', "
				. "@level1type = N'{$level1Type}', @level1name = N'{$name}';";
		}

		/**
		 * Builds the SQL Server routine declaration header.
		 * @param AstRoutineDefinition $routine The routine
		 * @param array<string, string> $parameters SQL type of each parameter, by variable name
		 * @return string
		 */
		private function header(AstRoutineDefinition $routine, array $parameters): string {
			$list = implode(', ', array_map(fn(string $name, string $type) => "{$name} {$type}", array_keys($parameters), $parameters));
			$name = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema);

			if ($routine->returnsNoValue()) {
				return "CREATE PROCEDURE {$name}" . ($list === '' ? '' : " {$list}");
			}

			return "CREATE FUNCTION {$name}({$list})\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType());
		}
		
		/**
		 * Compiles a routine variable assignment.
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `SET @name = value;`
		 * @throws EntityResolutionException
		 * @throws QuelException
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
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			$result = '';

			foreach (array_reverse($this->openLoopCursors()) as $cursorName) {
				$result .= $this->lines(['CLOSE ' . $this->cursorName($cursorName) . ';', 'DEALLOCATE ' . $this->cursorName($cursorName) . ';'], $depth);
			}

			$value = $return->getValue();

			if ($value === null) {
				return $result . $this->line('RETURN;', $depth);
			}

			return $result . $this->line('RETURN ' . $this->returnedValue($value) . ';', $depth);
		}

		/**
		 * Compiles a conditional routine statement.
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
		 * Compiles a while loop for the target database engine.
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function lowerWhile(AstWhile $while, int $depth): string {
			$this->pushLoop(null);
			$body = $this->lowerBlock($while->getBody(), $depth + 1);
			$frame = $this->popLoop();
			
			return $this->nextLabel($frame, $depth)
				. $this->line('WHILE ' . $this->statements->compileCondition($while->getCondition()), $depth)
				. $this->block($body, $depth, true)
				. $this->breakLabel($frame, $depth);
		}

		/**
		 * Compiles a cursor iteration loop.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerForeach(AstForeach $foreach, int $depth): string {
			$cursorName = $foreach->getCursorName();
			$cursor = $this->cursorName($cursorName);
			$select = $this->statements->retrieveSql($this->cursorQueries[$cursorName]);
			$declaration = "DECLARE {$cursor} CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR {$select};";

			$fetch = $this->lines([
				"FETCH NEXT FROM {$cursor} INTO " . implode(', ', $this->fieldVariables($cursorName)) . ';',
				'IF @@FETCH_STATUS <> 0 BREAK;',
			], $depth + 1);
			
			$this->pushLoop($cursorName);
			$body = $this->lowerBlock($foreach->getBody(), $depth + 1);
			$frame = $this->popLoop();

			return $this->lines([$declaration, "OPEN {$cursor};"], $depth)
				. $this->nextLabel($frame, $depth)
				. $this->lines(['WHILE 1 = 1', 'BEGIN'], $depth)
				. $fetch
				. $body
				. $this->line('END;', $depth)
				. $this->breakLabel($frame, $depth)
				. $this->lines(["CLOSE {$cursor};", "DEALLOCATE {$cursor};"], $depth);
		}
		
		/**
		 * Uses a savepoint for caller-owned transactions and commits only transactions started here.
		 * @param AstAtomic $atomic The atomic block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function lowerAtomicBlock(AstAtomic $atomic, int $depth): string {
			$number = ++$this->atomicBlockCount;
			$this->atomicOwnerVariable = '@_equel_owns_' . $number;
			$this->atomicSavepointVariable = '@_equel_savepoint_' . $number;
			$this->atomicVariables[$this->atomicOwnerVariable] = 'BIT';
			$this->atomicVariables[$this->atomicSavepointVariable] = 'VARCHAR(32)';
			$owner = $this->atomicOwnerVariable;
			$savepoint = $this->atomicSavepointVariable;
			$body = $this->lowerBlock($atomic->getBody(), $depth + 1);

			return $this->line("SET {$owner} = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;", $depth)
				. $this->line("IF {$owner} = 1 BEGIN TRANSACTION;", $depth)
				. $this->line("IF {$owner} = 0 BEGIN", $depth)
				. $this->line("SET {$savepoint} = REPLACE(CONVERT(VARCHAR(36), NEWID()), '-', '');", $depth + 1)
				. $this->line("SAVE TRANSACTION {$savepoint};", $depth + 1)
				. $this->line('END;', $depth)
				. $this->line('BEGIN TRY', $depth)
				. $body
				. $this->line("IF {$owner} = 1 AND @@TRANCOUNT > 0 COMMIT TRANSACTION;", $depth + 1)
				. $this->line('END TRY', $depth)
				. $this->line('BEGIN CATCH', $depth)
				. $this->line("IF {$owner} = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;", $depth + 1)
				. $this->line("ELSE IF {$owner} = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION {$savepoint};", $depth + 1)
				. $this->line('THROW;', $depth + 1)
				. $this->line('END CATCH;', $depth);
		}

		/**
		 * Compiles the routine rollback statement.
		 * @return string
		 */
		protected function rollbackStatement(): string {
			return "IF {$this->atomicOwnerVariable} = 1 BEGIN ROLLBACK TRANSACTION; END ELSE BEGIN ROLLBACK TRANSACTION {$this->atomicSavepointVariable}; END;";
		}

		/**
		 * Compiles a break statement for the target engine.
		 * @param int $levels Number of loops to leave
		 * @param int $depth Indentation depth
		 * @return string Jump and skipped cursor cleanup
		 */
		protected function breakStatement(int $levels, int $depth): string {
			if ($levels === 1) {
				return $this->line('BREAK;', $depth);
			}

			$target = $this->targetLoop($levels, true);
			return $this->skippedCursorCleanup($levels, $depth) . $this->line('GOTO _break_' . substr($target['label'], 5) . ';', $depth);
		}

		/**
		 * Jumps to `WHILE`; a cursor loop fetches its next row at the top of the body.
		 * @param int $levels Number of loops to target
		 * @param int $depth Indentation depth
		 * @return string Jump and skipped cursor cleanup
		 */
		protected function continueStatement(int $levels, int $depth): string {
			if ($levels === 1) {
				return $this->line('CONTINUE;', $depth);
			}

			$target = $this->targetLoop($levels, false);
			return $this->skippedCursorCleanup($levels, $depth) . $this->line('GOTO _next_' . substr($target['label'], 5) . ';', $depth);
		}

		/**
		 * Closes and deallocates cursors in loops skipped by a jump.
		 * @param int $levels Level of the jump
		 * @param int $depth Indentation depth
		 * @return string Cursor cleanup statements
		 */
		private function skippedCursorCleanup(int $levels, int $depth): string {
			$result = '';
			
			foreach ($this->skippedCursors($levels) as $cursorName) {
				$cursor = $this->cursorName($cursorName);
				$result .= $this->lines(["CLOSE {$cursor};", "DEALLOCATE {$cursor};"], $depth);
			}
			
			return $result;
		}

		/**
		 * Labels the entry to a targeted loop.
		 * @param LoopFrame $frame Loop frame
		 * @param int $depth Indentation depth
		 * @return string Label or empty string
		 */
		private function nextLabel(array $frame, int $depth): string {
			return $frame['continueTarget'] ? $this->line('_next_' . substr($frame['label'], 5) . ':', $depth) : '';
		}

		/**
		 * Labels the exit from a targeted loop before its cursor cleanup.
		 * @param LoopFrame $frame Loop frame
		 * @param int $depth Indentation depth
		 * @return string Label and no-op, or empty string
		 */
		private function breakLabel(array $frame, int $depth): string {
			if (!$frame['breakTarget']) {
				return '';
			}

			$this->usesNoopVariable = true;
			return $this->lines(['_break_' . substr($frame['label'], 5) . ':', 'SET ' . self::NOOP_VARIABLE . ' = 0;'], $depth);
		}

		/**
		 * Stores each argument EXEC can't take in a local, then calls the procedure with it.
		 * @param AstCall $statement The call
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		protected function lowerCall(AstCall $statement, int $depth): string {
			$name = $statement->getCall()->getName();
			$lines = [];
			$argumentSql = [];

			foreach ($statement->getCall()->getArguments() as $index => $argument) {
				$isVariable = $argument instanceof AstIdentifier && $argument->getType()->isRoutineReference();

				if ($isVariable || in_array(get_class($argument), QuelToSQLCall::LITERAL_ARGUMENTS, true)) {
					continue;
				}

				$variable = '@_arg' . (count($this->argumentVariables) + 1);
				$this->argumentVariables[$variable] = $this->statements->callArgumentSqlType($argument, $name);
				$lines[] = "SET {$variable} = " . $this->statements->compileCallArgument($argument, $name) . ';';
				$argumentSql[$index] = $variable;
			}

			$lines[] = $this->statements->compileCall($statement, $argumentSql) . ';';
			return $this->lines($lines, $depth);
		}

		/**
		 * Compiles a query row count assignment for SQL Server.
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
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function discardRetrieve(AstRetrieve $retrieve): string {
			$sql = $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve));

			if ($this->isFunction) {
				$this->usesDiscardVariable = true;

				if ($retrieve->getWindow() === null && !empty($retrieve->getSort())) {
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