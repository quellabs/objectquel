<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Path checks over a routine body: every path of a non-void routine ends in
	 * `return`, and `abort` is the last statement on its path through its
	 * `begin transaction` block (v1 has no guard for statements after a rollback).
	 */
	class RoutineControlFlowValidator {

		/**
		 * @param AstRoutineDefinition $routine Routine whose names and placements are already validated
		 * @return void
		 * @throws SemanticException
		 */
		public function validate(AstRoutineDefinition $routine): void {
			$this->checkBlock($routine->getBody(), false, false);

			if (!$routine->isVoid() && !$this->alwaysReturns($routine->getBody())) {
				throw new SemanticException("Not every path through '{$routine->getName()}' ends in a return, but it declares return type '{$routine->getDeclaredReturnType()}'.");
			}
		}

		/**
		 * Checks transaction/abort/return placement in a statement list.
		 * @param AstInterface[] $statements Statements in source order
		 * @param bool $inTransaction True inside a `begin transaction` body
		 * @param bool $inLoop True inside a loop that is itself inside the transaction
		 * @return bool True when some path through the list ends in `abort`
		 * @throws SemanticException
		 */
		private function checkBlock(array $statements, bool $inTransaction, bool $inLoop): bool {
			$mayAbort = false;

			foreach ($statements as $statement) {
				if ($mayAbort) {
					throw new SemanticException("A statement follows 'abort' on the same path. 'abort' must be the last statement on its path through the transaction block.");
				}

				$mayAbort = $this->checkStatement($statement, $inTransaction, $inLoop);
			}

			return $mayAbort;
		}

		/**
		 * Checks one statement, recursing into nested blocks.
		 * @param AstInterface $statement The statement
		 * @param bool $inTransaction True inside a `begin transaction` body
		 * @param bool $inLoop True inside a loop that is itself inside the transaction
		 * @return bool True when some path through the statement ends in `abort`
		 * @throws SemanticException
		 */
		private function checkStatement(AstInterface $statement, bool $inTransaction, bool $inLoop): bool {
			if ($statement instanceof AstAbort) {
				if (!$inTransaction) {
					throw new SemanticException("'abort' is only valid inside 'begin transaction { }'.");
				}

				if ($inLoop) {
					throw new SemanticException("'abort' inside a loop would let later iterations run after the rollback; move it out of the loop.");
				}

				return true;
			}

			if ($statement instanceof AstReturn && $inTransaction) {
				throw new SemanticException("'return' inside 'begin transaction { }' is not supported: it would leave the transaction neither committed nor rolled back.");
			}

			if ($statement instanceof AstIf) {
				$thenMayAbort = $this->checkBlock($statement->getThenBody(), $inTransaction, $inLoop);
				$elseMayAbort = $this->checkBlock($statement->getElseBody() ?? [], $inTransaction, $inLoop);
				return $thenMayAbort || $elseMayAbort;
			}

			if ($statement instanceof AstWhile || $statement instanceof AstForeach) {
				$this->checkBlock($statement->getBody(), $inTransaction, $inTransaction);
				return false;
			}

			if ($statement instanceof AstBeginTransaction) {
				if ($inTransaction) {
					throw new SemanticException("'begin transaction' blocks can't be nested.");
				}

				// abort ends the transaction block, not the enclosing path
				$this->checkBlock($statement->getBody(), true, false);
				return false;
			}

			return false;
		}

		/**
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
