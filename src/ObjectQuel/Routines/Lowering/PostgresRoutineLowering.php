<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\RoutineReferenceSql;

	/**
	 * Lowers an analyzed routine to a PL/pgSQL CREATE FUNCTION (non-void) or
	 * CREATE PROCEDURE (void).
	 *
	 * - Locals and parameter copies live in the top block labelled `_routine`
	 *   and are read as `"_routine"."name"`, so they never resolve as columns.
	 * - Every `foreach` is a plain `FOR row IN query LOOP`; a `replace`/`delete`
	 *   naming a table inside it writes it the ordinary way, with its own
	 *   explicit `where`, same as anywhere else in the routine.
	 * - `atomic { }` uses a PL/pgSQL exception block as a subtransaction.
	 */
	class PostgresRoutineLowering extends RoutineLowering {

		/**
		 * Returns the target engine name.
		 * @return string Engine name for error messages
		 */
		protected function engineName(): string {
			return 'PostgreSQL';
		}

		/**
		 * Renders the routine definition as SQL.
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> The CREATE FUNCTION/PROCEDURE statement
		 */
		protected function render(AstRoutineDefinition $routine): array {
			$body = $this->lowerBlock($routine->getBody(), 1);
			$declarations = $this->declarations($routine);
			$declareSection = $declarations === '' ? '' : "DECLARE\n{$declarations}";
			$block = "<<" . RoutineReferenceSql::POSTGRES_BLOCK_LABEL . ">>\n{$declareSection}BEGIN\n{$body}END;";
			$tag = $this->dollarQuoteTag($block);
			$create = $this->header($routine) . "\nLANGUAGE plpgsql\nAS {$tag}\n{$block}\n{$tag};";

			// COMMENT ON [FUNCTION|PROCEDURE] needs the argument types to disambiguate an overload, same as the CREATE signature
			$argTypes = implode(', ', array_column($this->flattenedParameters($routine), 'sqlType'));
			$signature = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema) . "({$argTypes})";
			$kind = $routine->returnsNoValue() ? 'PROCEDURE' : 'FUNCTION';
			$comment = "COMMENT ON {$kind} {$signature} IS " . $this->quoter->quoteStringLiteral($this->metadataJson) . ';';

			return [$create, $comment];
		}

		/**
		 * Builds the PostgreSQL routine declaration header.
		 * @param AstRoutineDefinition $routine The routine
		 * @return string `CREATE FUNCTION name(params) RETURNS type` or the PROCEDURE form
		 */
		private function header(AstRoutineDefinition $routine): string {
			$parameters = [];

			foreach ($this->flattenedParameters($routine) as ['name' => $name, 'sqlType' => $sqlType]) {
				$parameters[] = $this->quoter->quoteIdentifier($name) . ' ' . $sqlType;
			}

			$signature = $this->quoter->quoteRoutineName($routine->getName(), $this->routineSchema) . '(' . implode(', ', $parameters) . ')';

			if ($routine->returnsNoValue()) {
				return "CREATE PROCEDURE {$signature}";
			}

			return "CREATE FUNCTION {$signature}\nRETURNS " . $this->sqlType($routine->getDeclaredReturnType());
		}

		/**
		 * Declares parameter copies, locals, cursor row records and explicit cursors in the labelled block.
		 * @param AstRoutineDefinition $routine The routine
		 * @return string DECLARE section lines
		 * @throws SemanticException
		 */
		private function declarations(AstRoutineDefinition $routine): string {
			$lines = [];

			// Copies shadow the parameters so every variable is qualified with the same label.
			// An entity-row parameter is already flattened to one native positional parameter
			// per mapped column, so its copies are indexed the same way as any other.
			foreach ($this->flattenedParameters($routine) as $index => ['name' => $name, 'sqlType' => $sqlType]) {
				$lines[] = $this->quoter->quoteIdentifier($name) . ' ' . $sqlType . ' := $' . ($index + 1) . ';';
			}

			foreach ($this->scalarDeclarations($routine) as $statement) {
				$lines[] = $this->quoter->quoteIdentifier($statement->getName()) . ' ' . $this->sqlType($statement->getType()) . ';';
			}

			foreach (array_keys($this->cursorQueries) as $cursorName) {
				$lines[] = $this->quoter->quoteIdentifier(RoutineReferenceSql::cursorRowVariable($cursorName)) . ' RECORD;';
			}

			return $this->lines($lines, 1);
		}

		/**
		 * Compiles a routine variable assignment.
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string `"name" := value;`
		 * @throws SemanticException
		 */
		protected function assignment(string $name, AstInterface $value): string {
			return $this->quoter->quoteIdentifier($name) . ' := ' . $this->assignedValue($name, $value) . ';';
		}

		/**
		 * Compiles a routine RETURN statement.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		protected function lowerReturn(AstReturn $return, int $depth): string {
			$value = $return->getValue();

			if ($value === null) {
				return $this->line('RETURN;', $depth);
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
			$result = $this->line('IF ' . $this->statements->compileCondition($if->getCondition()) . ' THEN', $depth);
			$result .= $this->lowerBlock($if->getThenBody(), $depth + 1);

			if ($if->getElseBody() !== null) {
				$result .= $this->line('ELSE', $depth);
				$result .= $this->lowerBlock($if->getElseBody(), $depth + 1);
			}

			return $result . $this->line('END IF;', $depth);
		}
		
		/**
		 * Compiles a while loop for the target database engine.
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws TransformationException
		 */
		protected function lowerWhile(AstWhile $while, int $depth): string {
			$label = $this->pushLoop(null);
			$body = $this->lowerBlock($while->getBody(), $depth + 1);
			$frame = $this->popLoop();
			
			return ($frame['breakTarget'] || $frame['continueTarget'] ? $this->line("<<{$label}>>", $depth) : '')
				. $this->line('WHILE ' . $this->statements->compileCondition($while->getCondition()) . ' LOOP', $depth)
				. $body
				. $this->line('END LOOP;', $depth);
		}
		
		/**
		 * `FOR row IN query LOOP`, PL/pgSQL's own cursor loop.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function lowerForeach(AstForeach $foreach, int $depth): string {
			$cursorName = $foreach->getCursorName();
			$row = $this->quoter->quoteIdentifier(RoutineReferenceSql::cursorRowVariable($cursorName));
			
			$label = $this->pushLoop($cursorName);
			$body = $this->lowerBlock($foreach->getBody(), $depth + 1);
			$frame = $this->popLoop();

			return ($frame['breakTarget'] || $frame['continueTarget'] ? $this->line("<<{$label}>>", $depth) : '')
				. $this->line("FOR {$row} IN " . $this->statements->retrieveSql($this->cursorQueries[$cursorName]) . ' LOOP', $depth)
				. $body
				. $this->line('END LOOP;', $depth);
		}
		
		/**
		 * Compiles an atomic block in the routine.
		 * @param AstAtomic $atomic The atomic block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException When an embedded statement can't be compiled
		 * @throws TransformationException
		 */
		protected function lowerAtomic(AstAtomic $atomic, int $depth): string {
			$body = $this->lowerBlock($atomic->getBody(), $depth + 1);
			
			return $this->line('BEGIN', $depth)
				. ($body === '' ? $this->line('NULL;', $depth + 1) : $body)
				. $this->line('EXCEPTION WHEN SQLSTATE \'PZ001\' THEN', $depth)
				. $this->line('NULL;', $depth + 1)
				. $this->line('END;', $depth);
		}

		/**
		 * Compiles the routine rollback statement.
		 * @return string
		 */
		protected function rollbackStatement(): string {
			return "RAISE SQLSTATE 'PZ001';";
		}

		/**
		 * An unlabelled EXIT leaves the innermost loop, never the `_routine` block.
		 * @param int $levels Number of loops to leave
		 * @param int $depth Indentation depth
		 * @return string Jump statement
		 */
		protected function breakStatement(int $levels, int $depth): string {
			if ($levels === 1) {
				return $this->line('EXIT;', $depth);
			}

			return $this->line('EXIT ' . $this->targetLoop($levels, true)['label'] . ';', $depth);
		}

		/**
		 * Compiles a continue statement for the target engine.
		 * @param int $levels Number of loops to target
		 * @param int $depth Indentation depth
		 * @return string Jump statement
		 */
		protected function continueStatement(int $levels, int $depth): string {
			if ($levels === 1) {
				return $this->line('CONTINUE;', $depth);
			}

			return $this->line('CONTINUE ' . $this->targetLoop($levels, false)['label'] . ';', $depth);
		}
		
		/**
		 * PERFORM runs a query and discards its rows.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return string
		 * @throws EntityResolutionException
		 * @throws QuelException
		 * @throws SemanticException
		 * @throws TransformationException
		 */
		protected function discardRetrieve(AstRetrieve $retrieve): string {
			$sql = $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve));

			if (!str_starts_with($sql, 'SELECT ')) {
				throw new \LogicException("Expected a SELECT statement, got: {$sql}");
			}

			return 'PERFORM ' . substr($sql, strlen('SELECT ')) . ';';
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