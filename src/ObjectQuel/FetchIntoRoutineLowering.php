<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineReferenceSql;

	/**
	 * Shared lowering for engines without row variables (SQL Server, MySQL/MariaDB):
	 * every `foreach` opens a cursor and fetches each field into its own typed
	 * variable, and a discarded retrieve counts its rows into a scratch variable.
	 */
	abstract class FetchIntoRoutineLowering extends RoutineLowering {

		/** @var array<string, array<string, string>> SQL type of each fetched field, by cursor and field, in select-list order */
		protected array $cursorFields;

		/** True once a discarded retrieve needs the scratch variable */
		protected bool $usesDiscardVariable;

		/**
		 * @param string $name Local or parameter name
		 * @return string The variable as written in SQL
		 * @throws QuelException
		 */
		protected function variableName(string $name): string {
			return RoutineReferenceSql::variable($name, $this->quoter, $this->platform->getDatabaseType());
		}

		/**
		 * @param string $cursorName Cursor name
		 * @return string[] Variables the cursor's fields are fetched into, in select-list order
		 * @throws QuelException
		 */
		protected function fieldVariables(string $cursorName): array {
			return array_map(
				fn(string $field) => RoutineReferenceSql::cursorFieldVariable($cursorName, $field, $this->platform->getDatabaseType()),
				array_keys($this->cursorFields[$cursorName])
			);
		}

		/**
		 * @param string $cursorName Cursor name
		 * @return string Name of the engine cursor
		 */
		protected function cursorName(string $cursorName): string {
			return "_cur_{$cursorName}";
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return array<string, string> SQL type of every parameter, by variable name as written in SQL
		 * @throws QuelException
		 */
		protected function parameterVariables(AstRoutineDefinition $routine): array {
			$variables = [];

			foreach ($routine->getParameters() as $parameter) {
				$variables[$this->variableName($parameter->getName())] = $this->sqlType($parameter->getType());
			}

			return $variables;
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return array<string, string> SQL type of every scalar local, by variable name as written in SQL
		 * @throws QuelException
		 */
		protected function localVariables(AstRoutineDefinition $routine): array {
			$variables = [];

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstDeclare && !$statement->isCursor()) {
					$variables[$this->variableName($statement->getName())] = $this->sqlType($statement->getType());
				}
			}

			return $variables;
		}

		/**
		 * @return array<string, string> SQL type of every field variable, by variable name
		 * @throws QuelException
		 */
		protected function fieldVariableTypes(): array {
			$variables = [];

			foreach ($this->cursorFields as $cursorName => $fields) {
				$variables += array_combine($this->fieldVariables($cursorName), array_values($fields));
			}

			return $variables;
		}

		/**
		 * Resets the per-routine lowering state.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException
		 */
		protected function validate(AstRoutineDefinition $routine): void {
			$this->cursorFields = [];
			$this->usesDiscardVariable = false;
		}

		/**
		 * Prepares a cursor query and types the variables its fields are fetched into.
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $initializer The cursor's retrieve, as analyzed
		 * @return AstRetrieve The prepared query
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function prepareCursorQuery(string $cursorName, AstRetrieve $initializer): AstRetrieve {
			$query = $this->prepareFetchedQuery($cursorName, $initializer);
			$this->cursorFields[$cursorName] = $this->statements->getFieldTypes()->declareCursor($cursorName, $query);
			return $query;
		}

		/**
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $initializer The cursor's retrieve, as analyzed
		 * @return AstRetrieve The prepared query
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function prepareFetchedQuery(string $cursorName, AstRetrieve $initializer): AstRetrieve {
			return parent::prepareCursorQuery($cursorName, $initializer);
		}

		/**
		 * Every loop here runs on an OPENed cursor, which a COMMIT may close.
		 * @param AstBeginTransaction $transaction The transaction block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function lowerTransaction(AstBeginTransaction $transaction, int $depth): string {
			if (!empty($this->openLoops)) {
				throw new SemanticException("'begin transaction' inside 'foreach {$this->openLoops[0]}' isn't supported on {$this->engineName()}: the loop's cursor may not survive the COMMIT.");
			}

			return $this->lowerTransactionBlock($transaction, $depth);
		}

		/**
		 * @param AstBeginTransaction $transaction The transaction block, outside any loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerTransactionBlock(AstBeginTransaction $transaction, int $depth): string;

		/**
		 * Runs the query and counts its rows into a scratch variable, discarding them.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function discardRetrieve(AstRetrieve $retrieve): string {
			$this->usesDiscardVariable = true;
			return $this->countInto('(' . $this->statements->retrieveSql($this->statements->prepareRetrieve($retrieve)) . ')');
		}

		/**
		 * @param string $derivedTable Parenthesized SELECT
		 * @return string Statement counting the derived table's rows into the scratch variable
		 */
		abstract protected function countInto(string $derivedTable): string;
	}
