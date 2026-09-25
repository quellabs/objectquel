<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineReferenceSql;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineStatementCompiler;

	/**
	 * Lowers an analyzed routine to a MySQL/MariaDB CREATE FUNCTION (non-void) or
	 * PROCEDURE (void). MySQL has no CREATE OR REPLACE for routines, so it gets a
	 * DROP ... IF EXISTS first; MariaDB gets CREATE OR REPLACE.
	 *
	 * - Locals and parameters are prefixed `_v_`, because MySQL resolves a name to
	 *   a variable before a column.
	 * - Cursors are read-only, so a current-row write matches the row's primary key,
	 *   which the cursor query fetches too (an approved exception to the no-hidden-
	 *   column rule). One NOT FOUND handler sets `_done`; each FETCH resets it first.
	 */
	class MysqlRoutineLowering extends FetchIntoRoutineLowering {

		private const string DONE_VARIABLE = '_done';
		private const string DISCARD_VARIABLE = '_discard';

		/** Target-list alias of an added primary-key column; QUEL aliases can't start with '_' */
		private const string KEY_ALIAS_PREFIX = '_pk_';

		/** @var array<string, array<string, string>> Field holding each primary-key property, by write cursor */
		private array $keyFields;

		private int $loopCount;

		/** @var string[] Labels of the loops enclosing the statement being lowered, outermost first */
		private array $loopLabels;

		/** Collation of string variables and return values, or null for the database default */
		private ?string $collation;

		/**
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
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return $this->platform->getDatabaseType() === 'mariadb' ? 'MariaDB' : 'MySQL';
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException
		 */
		protected function validate(AstRoutineDefinition $routine): void {
			parent::validate($routine);
			$this->keyFields = [];
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
		 * Adds the source's primary-key columns to a write cursor's query unless it already selects them.
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $initializer The cursor's retrieve, as analyzed
		 * @return AstRetrieve The prepared query
		 * @throws SemanticException When the source entity has no primary key
		 */
		protected function prepareFetchedQuery(string $cursorName, AstRetrieve $initializer): AstRetrieve {
			if (!isset($this->writeCursors[$cursorName])) {
				return $this->statements->prepareRetrieve($initializer);
			}

			$source = $this->currentRowSource($cursorName);
			$keys = $this->entityStore->getMetadata($source->getEntityName())->identifierKeys;

			if (empty($keys)) {
				throw new SemanticException("Cursor '{$cursorName}' reads {$source->getEntityName()}, which has no primary key, so {$this->engineName()} can't find the current row for 'delete {$cursorName}'/'replace {$cursorName}'.");
			}

			$extraValues = [];

			foreach ($keys as $key) {
				$field = $this->selectedColumnAlias($initializer, $source, $key);

				if ($field === null) {
					$field = self::KEY_ALIAS_PREFIX . $key;
					$extraValues[] = new AstAlias($field, $this->columnReference($source, $key));
				}

				$this->keyFields[$cursorName][$key] = $field;
			}

			return $this->statements->prepareRetrieve($initializer, $extraValues);
		}

		/**
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> DROP ... IF EXISTS (MySQL only) and the CREATE statement
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

			$create = $this->header($routine, $parameters) . "\nBEGIN\n" . $this->lines($declarations, 1) . $body . 'END';

			if ($this->platform->getDatabaseType() === 'mariadb') {
				return [$create];
			}

			$kind = $routine->isVoid() ? 'PROCEDURE' : 'FUNCTION';
			return ["DROP {$kind} IF EXISTS " . $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema), $create];
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @param array<string, string> $parameters SQL type of each parameter, by variable name
		 * @return string
		 */
		private function header(AstRoutineDefinition $routine, array $parameters): string {
			$list = implode(', ', array_map(fn(string $name, string $type) => "{$name} {$type}", array_keys($parameters), $parameters));
			$orReplace = $this->platform->getDatabaseType() === 'mariadb' ? 'OR REPLACE ' : '';
			$signature = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema) . "({$list})";

			// Advisory on MySQL, but binary logging rejects a function without READS SQL DATA (or NO SQL/DETERMINISTIC)
			$dataAccess = $this->writesTables($routine) ? 'MODIFIES SQL DATA' : 'READS SQL DATA';

			if ($routine->isVoid()) {
				return "CREATE {$orReplace}PROCEDURE {$signature}\n{$dataAccess}";
			}

			return "CREATE {$orReplace}FUNCTION {$signature}\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType()) . "\n{$dataAccess}";
		}

		/**
		 * @param string $type Routine type name
		 * @return string SQL type, with the configured collation when it's a character type
		 */
		protected function sqlType(string $type): string {
			return $this->withCollation(parent::sqlType($type));
		}

		/**
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
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `SET _v_name = value;`
		 * @throws SemanticException
		 */
		protected function assignment(string $name, AstInterface $value): string {
			return 'SET ' . $this->variableName($name) . ' = ' . $this->assignedValue($name, $value) . ';';
		}

		/**
		 * Open cursors close at the end of the block they're declared in, so RETURN needs no CLOSE.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			return $this->line('RETURN ' . $this->returnedValue($return) . ';', $depth);
		}

		/**
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
		 * START TRANSACTION commits the work done so far; the closing COMMIT after `abort` has nothing left to commit.
		 * @param AstBeginTransaction $transaction The transaction block
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lowerTransactionBlock(AstBeginTransaction $transaction, int $depth): string {
			return $this->line('START TRANSACTION;', $depth)
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
		 * Matches the fetched primary key, since MySQL cursors can't be updated through.
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return string
		 */
		protected function currentRowCondition(string $cursorName): string {
			$source = $this->currentRowSource($cursorName);
			$metadata = $this->entityStore->getMetadata($source->getEntityName());
			$alias = $this->quoter->quoteIdentifier($source->getName());
			$conditions = [];

			foreach ($this->keyFields[$cursorName] as $key => $field) {
				$column = $alias . '.' . $this->quoter->quoteIdentifier($metadata->getColumnNameOrFail($key));
				$conditions[] = $column . ' = ' . RoutineReferenceSql::cursorFieldVariable($cursorName, $field, $this->platform->getDatabaseType());
			}

			return implode(' AND ', $conditions);
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
		 * @param AstRetrieve $retrieve Cursor retrieve, as analyzed
		 * @param AstRangeDatabase $source The cursor's source range
		 * @param string $property Column property of the source
		 * @return string|null Alias of a target-list entry that reads exactly `source.property`, or null
		 */
		private function selectedColumnAlias(AstRetrieve $retrieve, AstRangeDatabase $source, string $property): ?string {
			foreach ($retrieve->getValues() as $value) {
				$expression = $value->getExpression();

				if (
					$expression instanceof AstIdentifier &&
					$expression->getName() === $source->getName() &&
					$expression->getNext()?->getName() === $property &&
					!$expression->getNext()->hasNext()
				) {
					return $value->getName();
				}
			}

			return null;
		}

		/**
		 * @param AstRangeDatabase $source Range to read from
		 * @param string $property Column property
		 * @return AstIdentifier Unresolved `source.property`, as the parser builds it
		 */
		private function columnReference(AstRangeDatabase $source, string $property): AstIdentifier {
			$root = new AstIdentifier($source->getName());
			$next = new AstIdentifier($property);
			$next->setParent($root);
			$root->setNext($next);

			return $root;
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

			foreach ($routine->getBody() as $statement) {
				$range = $statement instanceof AstRangeDeclaration ? $statement->getRange() : null;

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
