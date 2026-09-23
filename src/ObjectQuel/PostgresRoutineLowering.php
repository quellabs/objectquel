<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeleteCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplaceCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

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
	class PostgresRoutineLowering {

		private const string INDENT = "\t";

		private RoutineStatementCompiler $statements;
		private RoutineCursorSource $cursorSource;
		private SqlIdentifierQuoter $quoter;
		private DDLTypeMapper $typeMapper;

		/** @var array<string, AstRetrieve> Prepared query of each cursor */
		private array $cursorQueries;

		/** @var array<string, AstRange[]> Ranges declared before each cursor */
		private array $cursorRanges;

		/** @var array<string, AstRetrieve> Original query of each cursor, as analyzed */
		private array $cursorDeclarations;

		/** @var array<string, true> Cursors whose loops write the current row */
		private array $explicitCursors;

		/** @var string[] Explicit cursors whose loops enclose the statement being lowered, outermost first */
		private array $openExplicitCursors;

		/**
		 * @param EntityStore $entityStore Entity metadata
		 * @param RoutineStatementCompiler $statements Compiles embedded statements for PostgreSQL
		 * @param PlatformCapabilitiesInterface $platform PostgreSQL platform description
		 */
		public function __construct(EntityStore $entityStore, RoutineStatementCompiler $statements, PlatformCapabilitiesInterface $platform) {
			$this->statements = $statements;
			$this->cursorSource = new RoutineCursorSource($entityStore);
			$this->quoter = new SqlIdentifierQuoter($platform);
			$this->typeMapper = new DDLTypeMapper($platform);
		}

		/**
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return string The CREATE OR REPLACE FUNCTION/PROCEDURE statement
		 * @throws SemanticException When the routine uses something PL/pgSQL can't express
		 * @throws EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): string {
			$this->cursorQueries = [];
			$this->cursorRanges = [];
			$this->cursorDeclarations = [];
			$this->explicitCursors = $this->collectExplicitCursors($routine);
			$this->openExplicitCursors = [];

			if (!$routine->isVoid() && $this->containsTransaction($routine)) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so PostgreSQL creates it as a FUNCTION, which can't commit or roll back. Make it void to use 'begin transaction'.");
			}

			$this->prepareCursors($routine);

			$body = $this->lowerBlock($routine->getBody(), 1);
			$declarations = $this->declarations($routine);
			$declareSection = $declarations === '' ? '' : "DECLARE\n{$declarations}";
			$block = "<<" . RoutineReferenceSql::POSTGRES_BLOCK_LABEL . ">>\n{$declareSection}BEGIN\n{$body}END;";
			$tag = $this->dollarQuoteTag($block);

			return $this->header($routine) . "\nLANGUAGE plpgsql\nAS {$tag}\n{$block}\n{$tag};";
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

			$signature = $this->quoter->quoteIdentifier($routine->getName()) . '(' . implode(', ', $parameters) . ')';

			if ($routine->isVoid()) {
				return "CREATE OR REPLACE PROCEDURE {$signature}";
			}

			return "CREATE OR REPLACE FUNCTION {$signature}\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType());
		}

		/**
		 * Declares parameter copies, locals, cursor row records and explicit cursors in the labelled block.
		 * @param AstRoutineDefinition $routine The routine
		 * @return string DECLARE section lines
		 * @throws SemanticException|EntityResolutionException|QuelException
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
				if (isset($this->explicitCursors[$cursorName])) {
					$lines[] = $this->quoter->quoteIdentifier($cursorName) . ' CURSOR FOR ' . $this->statements->retrieveSql($query) . ' FOR UPDATE;';
				}
			}

			return implode('', array_map(fn(string $line) => self::INDENT . $line . "\n", $lines));
		}

		/**
		 * Prepares every cursor query up front, so unused cursors are checked too.
		 * @param AstRoutineDefinition $routine The routine; declarations are top-level only
		 * @return void
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function prepareCursors(AstRoutineDefinition $routine): void {
			$ranges = [];

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstRangeDeclaration) {
					$ranges[] = $statement->getRange();
					continue;
				}

				if (!$statement instanceof AstDeclare || !$statement->isCursor()) {
					continue;
				}

				$initializer = $statement->getInitializer();

				if (!$initializer instanceof AstRetrieve) {
					throw new \LogicException("Cursor '{$statement->getName()}' has no retrieve; RoutineAnalyzer should have rejected it.");
				}

				$name = $statement->getName();
				$this->cursorDeclarations[$name] = $initializer;
				$this->cursorRanges[$name] = $ranges;
				$this->cursorQueries[$name] = $this->statements->prepareRetrieve($initializer);

				// FOR UPDATE and WHERE CURRENT OF need one plain table, which the optimizer may have changed
				if (isset($this->explicitCursors[$name]) && count($this->cursorQueries[$name]->getRanges()) !== 1) {
					throw new SemanticException("Cursor '{$name}' compiles to a query over more than one table, so PostgreSQL can't update it through 'WHERE CURRENT OF'; 'delete {$name}'/'replace {$name}' aren't possible here.");
				}
			}
		}

		/**
		 * @param AstInterface[] $statements Statements of one block
		 * @param int $depth Indentation depth
		 * @return string Lowered statements, one or more lines each
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerBlock(array $statements, int $depth): string {
			$result = '';

			foreach ($statements as $statement) {
				$result .= $this->lowerStatement($statement, $depth);
			}

			return $result;
		}

		/**
		 * @param AstInterface $statement Statement to lower
		 * @param int $depth Indentation depth
		 * @return string Lowered lines
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerStatement(AstInterface $statement, int $depth): string {
			return match (true) {
				// Ranges and cursors are declared in the DECLARE section
				$statement instanceof AstRangeDeclaration => '',
				$statement instanceof AstDeclare => $this->lowerDeclaration($statement, $depth),
				$statement instanceof AstVariableAssignment => $this->line($this->assignment($statement->getName(), $statement->getValue()), $depth),
				$statement instanceof AstReturn => $this->lowerReturn($statement, $depth),
				$statement instanceof AstIf => $this->lowerIf($statement, $depth),
				$statement instanceof AstWhile => $this->line('WHILE ' . $this->statements->compileCondition($statement->getCondition()) . ' LOOP', $depth)
					. $this->lowerBlock($statement->getBody(), $depth + 1)
					. $this->line('END LOOP;', $depth),
				$statement instanceof AstForeach => $this->lowerForeach($statement, $depth),
				$statement instanceof AstBeginTransaction => $this->lowerTransaction($statement, $depth),
				$statement instanceof AstAbort => $this->line('ROLLBACK;', $depth),
				$statement instanceof AstDeleteCurrent => $this->line($this->statements->compileCurrentRowDelete(
					$this->currentRowSource($statement->getCursorName()),
					$this->currentOf($statement->getCursorName())
				) . ';', $depth),
				$statement instanceof AstReplaceCurrent => $this->line($this->statements->compileCurrentRowReplace(
					$this->currentRowSource($statement->getCursorName()),
					$statement->getAssignments(),
					$this->currentOf($statement->getCursorName())
				) . ';', $depth),
				$statement instanceof AstRetrieve => $this->line($this->perform($statement), $depth),
				$statement instanceof AstAppend => $this->line($this->statements->compileAppend($statement) . ';', $depth),
				$statement instanceof AstReplace => $this->line($this->statements->compileReplace($statement) . ';', $depth),
				$statement instanceof AstDelete => $this->line($this->statements->compileDelete($statement) . ';', $depth),
				default => throw new \LogicException('Unsupported routine statement ' . get_class($statement) . '; RoutineAnalyzer should have rejected it.'),
			};
		}

		/**
		 * A scalar initializer becomes an assignment at the declaration's position.
		 * @param AstDeclare $declaration The declaration
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		private function lowerDeclaration(AstDeclare $declaration, int $depth): string {
			$initializer = $declaration->getInitializer();

			if ($declaration->isCursor() || $initializer === null) {
				return '';
			}

			return $this->line($this->assignment($declaration->getName(), $initializer), $depth);
		}

		/**
		 * Closes the explicit cursors of enclosing loops before returning.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		private function lowerReturn(AstReturn $return, int $depth): string {
			$result = '';

			foreach (array_reverse($this->openExplicitCursors) as $cursorName) {
				$result .= $this->line('CLOSE ' . $this->quoter->quoteIdentifier($cursorName) . ';', $depth);
			}

			return $result . $this->line('RETURN ' . $this->statements->compileValue($return->getValue()) . ';', $depth);
		}

		/**
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerIf(AstIf $if, int $depth): string {
			$result = $this->line('IF ' . $this->statements->compileCondition($if->getCondition()) . ' THEN', $depth);
			$result .= $this->lowerBlock($if->getThenBody(), $depth + 1);

			if ($if->getElseBody() !== null) {
				$result .= $this->line('ELSE', $depth);
				$result .= $this->lowerBlock($if->getElseBody(), $depth + 1);
			}

			return $result . $this->line('END IF;', $depth);
		}

		/**
		 * `FOR row IN query LOOP` for read-only loops; OPEN/FETCH/CLOSE when the body writes the current row.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerForeach(AstForeach $foreach, int $depth): string {
			$cursorName = $foreach->getCursorName();
			$row = $this->quoter->quoteIdentifier(RoutineReferenceSql::cursorRowVariable($cursorName));

			if (!isset($this->explicitCursors[$cursorName])) {
				return $this->line("FOR {$row} IN " . $this->statements->retrieveSql($this->cursorQueries[$cursorName]) . ' LOOP', $depth)
					. $this->lowerBlock($foreach->getBody(), $depth + 1)
					. $this->line('END LOOP;', $depth);
			}

			$cursor = $this->quoter->quoteIdentifier($cursorName);

			$this->openExplicitCursors[] = $cursorName;
			$body = $this->lowerBlock($foreach->getBody(), $depth + 1);
			array_pop($this->openExplicitCursors);

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
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerTransaction(AstBeginTransaction $transaction, int $depth): string {
			// COMMIT closes cursors opened with OPEN; only FOR ... IN loops survive it
			if (!empty($this->openExplicitCursors)) {
				throw new SemanticException("'begin transaction' inside 'foreach {$this->openExplicitCursors[0]}' isn't possible on PostgreSQL: that loop writes the current row, so it uses a cursor that COMMIT would close.");
			}

			// abort is always last on its path (RoutineControlFlowValidator), so the closing COMMIT only ends the empty transaction ROLLBACK started
			return $this->line('COMMIT;', $depth)
				. $this->lowerBlock($transaction->getBody(), $depth)
				. $this->line('COMMIT;', $depth);
		}

		/**
		 * A standalone retrieve runs for its side effects only; PERFORM discards the rows.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function perform(AstRetrieve $retrieve): string {
			$sql = $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve));

			if (!str_starts_with($sql, 'SELECT ')) {
				throw new \LogicException("Expected a SELECT statement, got: {$sql}");
			}

			return 'PERFORM ' . substr($sql, strlen('SELECT ')) . ';';
		}

		/**
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `"name" := value;`
		 * @throws SemanticException
		 */
		private function assignment(string $name, AstInterface $value): string {
			return $this->quoter->quoteIdentifier($name) . ' := ' . $this->statements->compileValue($value) . ';';
		}

		/**
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return AstRangeDatabase The table a current-row write targets
		 * @throws SemanticException|EntityResolutionException
		 */
		private function currentRowSource(string $cursorName): AstRangeDatabase {
			return $this->cursorSource->resolve($cursorName, $this->cursorDeclarations[$cursorName], $this->cursorRanges[$cursorName]);
		}

		/**
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return string `CURRENT OF "cursor"`
		 */
		private function currentOf(string $cursorName): string {
			return 'CURRENT OF ' . $this->quoter->quoteIdentifier($cursorName);
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return array<string, true> Cursors named by a current-row delete or replace
		 */
		private function collectExplicitCursors(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes([AstDeleteCurrent::class, AstReplaceCurrent::class]);
			$routine->accept($collector);

			$cursors = [];

			foreach ($collector->getCollectedNodes() as $write) {
				$cursors[$write->getCursorName()] = true;
			}

			return $cursors;
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return bool True when the body contains `begin transaction`
		 */
		private function containsTransaction(AstRoutineDefinition $routine): bool {
			$collector = new CollectNodes(AstBeginTransaction::class);
			$routine->accept($collector);
			return !empty($collector->getCollectedNodes());
		}

		/**
		 * @param string $type Routine type name, e.g. `integer` or `int`
		 * @return string PostgreSQL type
		 */
		private function sqlType(string $type): string {
			return $this->typeMapper->getTempTableColumnType([
				'type'      => RoutineAnalyzer::normalizeType($type),
				'limit'     => null,
				'unsigned'  => false,
				'precision' => null,
				'scale'     => null,
				'values'    => null,
			]);
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

		/**
		 * @param string $text Line content
		 * @param int $depth Indentation depth
		 * @return string Indented line with a trailing newline
		 */
		private function line(string $text, int $depth): string {
			return str_repeat(self::INDENT, $depth) . $text . "\n";
		}
	}
