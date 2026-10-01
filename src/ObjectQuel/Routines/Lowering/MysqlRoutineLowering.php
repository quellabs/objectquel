<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineStatementCompiler;

	/**
	 * Lowers an analyzed routine to a MySQL/MariaDB CREATE FUNCTION (non-void) or
	 * PROCEDURE (void).
	 *
	 * - Locals and parameters are prefixed `_v_`, because MySQL resolves a name to
	 *   a variable before a column.
	 * - Cursors are read-only: `foreach` only ever fetches rows into typed
	 *   variables. A `replace`/`delete` naming a table writes it in the
	 *   ordinary way, with its own explicit `where`, same as anywhere else in
	 *   the routine. One NOT FOUND handler sets `_done`; each FETCH resets it first.
	 */
	class MysqlRoutineLowering extends FetchIntoRoutineLowering {

		private const string DONE_VARIABLE = '_done';
		private const string DISCARD_VARIABLE = '_discard';
		private const string ATOMIC_LABEL = '_equel_atomic';
		private const string ROUTINE_LABEL = '_equel_routine';

		private int $loopCount;

		/** @var string[] Labels of the loops enclosing the statement being lowered, outermost first */
		private array $loopLabels;

		/** Collation of string variables and return values, or null for the database default */
		private ?string $collation;

		/**
		 * Initializes SQL lowering for MySQL and MariaDB routines.
		 * @param EntityStore $entityStore Entity metadata
		 * @param RoutineStatementCompiler $statements Compiles embedded statements for the target engine
		 * @param string|null $collation Collation of string variables and return values, or null for the database default
		 * @throws QuelException When the collation isn't a plain collation name
		 */
		public function __construct(EntityStore $entityStore, RoutineStatementCompiler $statements, ?string $collation = null) {
			parent::__construct($entityStore, $statements);

			if ($collation !== null && !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
				throw new QuelException("'{$collation}' isn't a valid collation name.");
			}

			$this->collation = $collation;
		}

		/**
		 * Returns the target engine name.
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return $this->platform->getDatabaseType() === 'mariadb' ? 'MariaDB' : 'MySQL';
		}

		/**
		 * Checks routine constructs unsupported by the target engine.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException
		 */
		protected function validate(AstRoutineDefinition $routine): void {
			parent::validate($routine);
			$this->loopCount = 0;
			$this->loopLabels = [];

			if (!$routine->isVoid()) {
				$this->assertNotRecursive($routine);
			}
		}

		/**
		 * Rejects a function that calls itself. Functions and procedures have separate names, so a `call` doesn't count.
		 * @param AstRoutineDefinition $routine Non-void routine
		 * @return void
		 * @throws SemanticException When the function calls itself
		 */
		private function assertNotRecursive(AstRoutineDefinition $routine): void {
			$calls = new CollectNodes(AstRoutineCall::class);
			$routine->accept($calls);

			foreach ($calls->getCollectedNodes() as $call) {
				if (!$call->getParent() instanceof AstCall && strcasecmp($call->getName(), $routine->getName()) === 0) {
					throw new SemanticException("'{$routine->getName()}' calls itself, but {$this->engineName()} doesn't allow a stored function to be recursive.");
				}
			}
		}

		/**
		 * Renders the routine definition as SQL.
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> The CREATE statement
		 */
		protected function render(AstRoutineDefinition $routine): array {
			$body = $this->lowerBlock($routine->getBody(), 1);
			$parameters = $this->parameterVariables($routine);
			$variables = $this->localVariables($routine) + $this->fieldVariableTypes();

			if (!empty($this->cursorQueries)) {
				$variables[self::DONE_VARIABLE] = 'BOOLEAN';
			}

			if ($this->usesDiscardVariable) {
				$variables[self::DISCARD_VARIABLE] = 'INT';
			}

			$this->assertNoColumnShadowed($routine, array_merge(array_keys($parameters), array_keys($variables)));

			$declarations = [];

			foreach ($variables as $name => $type) {
				$declarations[] = "DECLARE {$name} {$type};";
			}

			// Cursors after variables, handler last, as MySQL requires
			foreach ($this->cursorQueries as $cursorName => $query) {
				$declarations[] = 'DECLARE ' . $this->cursorName($cursorName) . ' CURSOR FOR ' . $this->statements->retrieveSql($query) . ';';
			}

			if (!empty($this->cursorQueries)) {
				$declarations[] = 'DECLARE CONTINUE HANDLER FOR NOT FOUND SET ' . self::DONE_VARIABLE . ' = TRUE;';
			}

			// MySQL procedures have no RETURN; a bare `return` needs a labeled body to LEAVE
			$hasBareReturn = $routine->isVoid() && $this->contains($routine, [AstReturn::class]);
			$beginLabel = $hasBareReturn ? self::ROUTINE_LABEL . ': ' : '';
			$endLabel = $hasBareReturn ? ' ' . self::ROUTINE_LABEL : '';

			$create = $this->header($routine, $parameters) . "\n{$beginLabel}BEGIN\n" . $this->lines($declarations, 1) . $body . "END{$endLabel}";

			return [$create];
		}

		/**
		 * Builds the MySQL or MariaDB routine declaration header.
		 * @param AstRoutineDefinition $routine The routine
		 * @param array<string, string> $parameters SQL type of each parameter, by variable name
		 * @return string
		 */
		private function header(AstRoutineDefinition $routine, array $parameters): string {
			$list = implode(', ', array_map(fn(string $name, string $type) => "{$name} {$type}", array_keys($parameters), $parameters));
			$signature = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema) . "({$list})";

			// Advisory on MySQL, but binary logging rejects a function without READS SQL DATA (or NO SQL/DETERMINISTIC)
			$dataAccess = $this->writesTables($routine) ? 'MODIFIES SQL DATA' : 'READS SQL DATA';

			if ($routine->isVoid()) {
				$marker = $this->contains($routine, [AstAtomic::class]) ? "\nCOMMENT 'ObjectQuel:atomic-block'" : '';
				return "CREATE PROCEDURE {$signature}\n{$dataAccess}{$marker}";
			}

			return "CREATE FUNCTION {$signature}\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType()) . "\n{$dataAccess}";
		}

		/**
		 * Maps a routine type to its SQL representation.
		 * @param string $type Routine type name
		 * @return string SQL type, with the configured collation when it's a character type
		 */
		protected function sqlType(string $type): string {
			return $this->withCollation(parent::sqlType($type));
		}

		/**
		 * Returns SQL types for cursor field variables.
		 * @return array<string, string> SQL type of every field variable, by variable name, with the configured collation
		 * @throws QuelException
		 */
		protected function fieldVariableTypes(): array {
			return array_map($this->withCollation(...), parent::fieldVariableTypes());
		}

		/**
		 * Adds the configured collation to a character type. MySQL requires CHARACTER SET alongside it; a collation name starts with its character set's.
		 * @param string $sqlType SQL type
		 * @return string
		 */
		private function withCollation(string $sqlType): string {
			if ($this->collation === null || !preg_match('/^(VARCHAR|CHAR|TEXT|ENUM)\b/', $sqlType)) {
				return $sqlType;
			}

			$characterSet = explode('_', $this->collation, 2)[0];
			return "{$sqlType} CHARACTER SET {$characterSet} COLLATE {$this->collation}";
		}

		/**
		 * Compiles a routine variable assignment.
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `SET _v_name = value;`
		 * @throws SemanticException
		 */
		protected function assignment(string $name, AstInterface $value): string {
			return 'SET ' . $this->variableName($name) . ' = ' . $this->assignedValue($name, $value) . ';';
		}

		/**
		 * Open cursors close at the end of the block they're declared in, so neither form needs a CLOSE.
		 * MySQL procedures don't allow RETURN at all, so a bare `return` (void routines only) instead
		 * leaves the labeled routine body {@see render()} builds for exactly this.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			$value = $return->getValue();

			if ($value === null) {
				return $this->line('LEAVE ' . self::ROUTINE_LABEL . ';', $depth);
			}

			return $this->line('RETURN ' . $this->returnedValue($value) . ';', $depth);
		}

		/**
		 * Compiles a conditional routine statement.
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerIf(AstIf $if, int $depth): string {
			$result = $this->line('IF ' . $this->statements->compileCondition($if->getCondition()) . ' THEN', $depth)
				. $this->statementList($this->lowerBlock($if->getThenBody(), $depth + 1), $depth + 1);

			if ($if->getElseBody() !== null) {
				$result .= $this->line('ELSE', $depth) . $this->statementList($this->lowerBlock($if->getElseBody(), $depth + 1), $depth + 1);
			}

			return $result . $this->line('END IF;', $depth);
		}

		/**
		 * Labelled, since LEAVE and ITERATE name their loop.
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerWhile(AstWhile $while, int $depth): string {
			$label = $this->nextLoopLabel();
			$this->loopLabels[] = $label;
			$body = $this->statementList($this->lowerBlock($while->getBody(), $depth + 1), $depth + 1);
			array_pop($this->loopLabels);

			return $this->line("{$label}: WHILE " . $this->statements->compileCondition($while->getCondition()) . ' DO', $depth)
				. $body
				. $this->line("END WHILE {$label};", $depth);
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
			$label = $this->nextLoopLabel();

			// Reset before each FETCH: an inner loop, or anything else raising NOT FOUND, may have set it
			$fetch = $this->lines([
				'SET ' . self::DONE_VARIABLE . ' = FALSE;',
				"FETCH {$cursor} INTO " . implode(', ', $this->fieldVariables($cursorName)) . ';',
				'IF ' . self::DONE_VARIABLE . " THEN LEAVE {$label}; END IF;",
			], $depth + 1);

			$this->loopLabels[] = $label;
			$body = $this->lowerLoopBody($foreach, $depth + 1);
			array_pop($this->loopLabels);

			return $this->lines(["OPEN {$cursor};", "{$label}: LOOP"], $depth)
				. $fetch
				. $body
				. $this->lines(["END LOOP {$label};", "CLOSE {$cursor};"], $depth);
		}

		/**
		 * Labels are numbered: MySQL limits them to 16 characters.
		 * @return string A label no other loop in the routine uses
		 */
		private function nextLoopLabel(): string {
			return '_loop' . (++$this->loopCount);
		}

		/**
		 * Uses a savepoint inside a caller transaction; the preflight release rejects an autocommit call before body writes.
		 * @param AstAtomic $atomic The atomic block
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerAtomicBlock(AstAtomic $atomic, int $depth): string {
			$savepoint = $this->atomicSavepoint();
			$guard = $this->atomicGuard();
			return $this->line("IF COALESCE({$guard}, 0) <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recursive atomic block is not supported'; END IF;", $depth)
				. $this->line("SAVEPOINT {$savepoint};", $depth)
				. $this->line("RELEASE SAVEPOINT {$savepoint};", $depth)
				. $this->line("SAVEPOINT {$savepoint};", $depth)
				. $this->line("SET {$guard} = 1;", $depth)
				. $this->line(self::ATOMIC_LABEL . ': BEGIN', $depth)
				. $this->line('DECLARE EXIT HANDLER FOR SQLEXCEPTION', $depth + 1)
				. $this->line('BEGIN', $depth + 1)
				. $this->line("SET {$guard} = 0;", $depth + 2)
				. $this->line("ROLLBACK TO SAVEPOINT {$savepoint};", $depth + 2)
				. $this->line("RELEASE SAVEPOINT {$savepoint};", $depth + 2)
				. $this->line('RESIGNAL;', $depth + 2)
				. $this->line('END;', $depth + 1)
				. $this->lowerBlock($atomic->getBody(), $depth + 1)
				. $this->line("RELEASE SAVEPOINT {$savepoint};", $depth + 1)
				. $this->line("SET {$guard} = 0;", $depth + 1)
				. $this->line('END ' . self::ATOMIC_LABEL . ';', $depth);
		}

		/**
		 * Compiles the routine rollback statement.
		 * @return string
		 */
		protected function rollbackStatement(): string {
			$savepoint = $this->atomicSavepoint();
			return "ROLLBACK TO SAVEPOINT {$savepoint}; RELEASE SAVEPOINT {$savepoint}; SET {$this->atomicGuard()} = 0; LEAVE " . self::ATOMIC_LABEL . ';';
		}

		/**
		 * Names the savepoint per routine so ordinary nested procedure calls do not replace it.
		 * @return string A legal static savepoint identifier
		 */
		private function atomicSavepoint(): string {
			return 'equel_' . substr(hash('sha256', $this->routine->getName()), 0, 16);
		}

		/**
		 * Tracks an active block across procedure calls so recursion cannot overwrite its savepoint.
		 * @return string A session-variable name unique to this routine
		 */
		private function atomicGuard(): string {
			return '@_equel_guard_' . substr(hash('sha256', $this->routine->getName()), 0, 16);
		}

		/**
		 * A foreach's CLOSE follows its loop, so leaving it closes the cursor too.
		 * @return string
		 */
		protected function breakStatement(): string {
			return 'LEAVE ' . end($this->loopLabels) . ';';
		}

		/**
		 * A foreach's ITERATE re-runs the `_done` reset and FETCH at the top of the loop.
		 * @return string
		 */
		protected function continueStatement(): string {
			return 'ITERATE ' . end($this->loopLabels) . ';';
		}

		/**
		 * SELECT ... INTO fails on more than one row, so the rows are counted instead.
		 * @param string $derivedTable Parenthesized SELECT
		 * @return string
		 */
		protected function countInto(string $derivedTable): string {
			return 'SELECT COUNT(*) INTO ' . self::DISCARD_VARIABLE . " FROM {$derivedTable} AS " . $this->quoter->quoteIdentifier('_discard') . ';';
		}

		/**
		 * MySQL rejects an empty statement list in IF/WHILE; an empty BEGIN END is a valid statement.
		 * @param string $statements Lowered statements
		 * @param int $depth Indentation depth of the statements
		 * @return string
		 */
		private function statementList(string $statements, int $depth): string {
			return $statements === '' ? $this->line('BEGIN END;', $depth) : $statements;
		}

		/**
		 * Rejects a variable named like a column of a table the routine reads, which MySQL would read instead of the column.
		 * @param AstRoutineDefinition $routine The routine
		 * @param string[] $variables Every variable name as written in SQL
		 * @return void
		 * @throws SemanticException
		 */
		private function assertNoColumnShadowed(AstRoutineDefinition $routine, array $variables): void {
			$names = array_flip(array_map('strtolower', $variables));

			foreach ($routine->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}

				foreach ($this->entityStore->getMetadata($range->getEntityName())->columnMap as $column) {
					if (isset($names[strtolower($column)])) {
						throw new SemanticException("Generated variable '{$column}' has the name of a column of {$range->getEntityName()}, which {$this->engineName()} would resolve to the variable. Rename the routine variable.");
					}
				}
			}
		}
	}
