<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExit;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Path checks over a routine body: every path of a non-void routine ends in
	 * `return`, `exit` is the last statement on its path through its
	 * `transaction` block,
	 * and `break`/`continue` sit in a loop without leaving a transaction block.
	 */
	class RoutineControlFlowValidator {

		/**
		 * Validates routine control flow and required return paths.
		 * @param AstRoutineDefinition $routine Routine whose names and placements are already validated
		 * @return void
		 * @throws SemanticException
		 */
		public function validate(AstRoutineDefinition $routine): void {
			$this->checkBlock($routine->getBody(), false, false, false);

			if (!$routine->isVoid() && !$this->alwaysReturns($routine->getBody())) {
				throw new SemanticException("Not every path through '{$routine->getName()}' ends in a return, but it declares return type '{$routine->getDeclaredReturnType()}'.");
			}
		}

		/**
		 * Checks transaction/exit/return/break/continue placement in a statement list.
		 * @param AstInterface[] $statements Statements in source order
		 * @param bool $inTransaction True inside a `transaction` body
		 * @param bool $inLoop True inside a loop that is itself inside the transaction
		 * @param bool $inAnyLoop True inside any loop
		 * @return bool True when some path through the list ends in `exit`
		 * @throws SemanticException
		 */
		private function checkBlock(array $statements, bool $inTransaction, bool $inLoop, bool $inAnyLoop): bool {
			$mayExit = false;

			foreach ($statements as $statement) {
				if ($mayExit) {
					throw new SemanticException("A statement follows 'exit' on the same path. 'exit' must be the last statement on its path through the transaction block.");
				}

				$mayExit = $this->checkStatement($statement, $inTransaction, $inLoop, $inAnyLoop);
			}

			return $mayExit;
		}

		/**
		 * Checks one statement, recursing into nested blocks.
		 * @param AstInterface $statement The statement
		 * @param bool $inTransaction True inside a `transaction` body
		 * @param bool $inLoop True inside a loop that is itself inside the transaction
		 * @param bool $inAnyLoop True inside any loop
		 * @return bool True when some path through the statement ends in `exit`
		 * @throws SemanticException
		 */
		private function checkStatement(AstInterface $statement, bool $inTransaction, bool $inLoop, bool $inAnyLoop): bool {
			if ($statement instanceof AstExit) {
				if (!$inTransaction) {
					throw new SemanticException("'exit' is only valid inside 'transaction { }'.");
				}

				if ($inLoop) {
					throw new SemanticException("'exit' inside a loop would let later iterations run after the rollback; move it out of the loop.");
				}

				return true;
			}

			if ($statement instanceof AstBreak || $statement instanceof AstContinue) {
				$this->checkLoopExit($statement instanceof AstBreak ? 'break' : 'continue', $inTransaction, $inLoop, $inAnyLoop);
				return false;
			}

			if ($statement instanceof AstReturn && $inTransaction) {
				throw new SemanticException("'return' inside 'transaction { }' is not supported; move it after the block.");
			}

			if ($statement instanceof AstIf) {
				$thenMayAbort = $this->checkBlock($statement->getThenBody(), $inTransaction, $inLoop, $inAnyLoop);
				$elseMayAbort = $this->checkBlock($statement->getElseBody() ?? [], $inTransaction, $inLoop, $inAnyLoop);
				return $thenMayAbort || $elseMayAbort;
			}

			if ($statement instanceof AstWhile || $statement instanceof AstForeach) {
				$this->checkBlock($statement->getBody(), $inTransaction, $inTransaction, true);
				return false;
			}

			if ($statement instanceof AstTransaction) {
				if ($inTransaction) {
					throw new SemanticException("'transaction' blocks can't be nested.");
				}

				// exit ends the transaction block, not the enclosing path
				$this->checkBlock($statement->getBody(), true, false, $inAnyLoop);
				return false;
			}

			return false;
		}

		/**
		 * Rejects `break`/`continue` outside a loop, or whose loop encloses the atomic block, skipping its cleanup.
		 * @param string $keyword 'break' or 'continue', for error messages
		 * @param bool $inTransaction True inside a `transaction` body
		 * @param bool $inLoop True inside a loop that is itself inside the transaction
		 * @param bool $inAnyLoop True inside any loop
		 * @return void
		 * @throws SemanticException
		 */
		private function checkLoopExit(string $keyword, bool $inTransaction, bool $inLoop, bool $inAnyLoop): void {
			if (!$inAnyLoop) {
				throw new SemanticException("'{$keyword}' is only valid inside 'while' or 'foreach'.");
			}

			if ($inTransaction && !$inLoop) {
				throw new SemanticException("'{$keyword}' would leave 'transaction { }' without finishing it; move the loop inside the block or the block out of the loop.");
			}
		}

		/**
		 * Checks whether all control-flow paths return a value.
		 * @param AstInterface[] $statements Statements in source order
		 * @return bool True when every path through the list reaches a `return`
		 */
		private function alwaysReturns(array $statements): bool {
			foreach ($statements as $statement) {
				if ($statement instanceof AstReturn) {
					return true;
				}

				// Loops may run zero times, so only if/else counts
				if (
					$statement instanceof AstIf &&
					$statement->getElseBody() !== null &&
					$this->alwaysReturns($statement->getThenBody()) &&
					$this->alwaysReturns($statement->getElseBody())
				) {
					return true;
				}
			}

			return false;
		}
	}
