<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRollback;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
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
	 * `return`, `rollback` is the last statement on its path through its
	 * `atomic` block,
	 * and `break`/`continue` sit in a loop without leaving an atomic block.
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
		 * Checks atomic/rollback/return/break/continue placement in a statement list.
		 * @param AstInterface[] $statements Statements in source order
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param bool $inLoop True inside a loop that is itself inside the atomic block
		 * @param bool $inAnyLoop True inside any loop
		 * @return bool True when some path through the list ends in `rollback`
		 * @throws SemanticException
		 */
		private function checkBlock(array $statements, bool $inAtomic, bool $inLoop, bool $inAnyLoop): bool {
			$mayRollback = false;

			foreach ($statements as $statement) {
				if ($mayRollback) {
					throw new SemanticException("A statement follows 'rollback' on the same path. 'rollback' must be the last statement on its path through the atomic block.");
				}

				$mayRollback = $this->checkStatement($statement, $inAtomic, $inLoop, $inAnyLoop);
			}

			return $mayRollback;
		}

		/**
		 * Checks one statement, recursing into nested blocks.
		 * @param AstInterface $statement The statement
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param bool $inLoop True inside a loop that is itself inside the atomic block
		 * @param bool $inAnyLoop True inside any loop
		 * @return bool True when some path through the statement ends in `rollback`
		 * @throws SemanticException
		 */
		private function checkStatement(AstInterface $statement, bool $inAtomic, bool $inLoop, bool $inAnyLoop): bool {
			if ($statement instanceof AstRollback) {
				if (!$inAtomic) {
					throw new SemanticException("'rollback' is only valid inside 'atomic { }'.");
				}

				if ($inLoop) {
					throw new SemanticException("'rollback' inside a loop would let later iterations run after it; move it out of the loop.");
				}

				return true;
			}

			if ($statement instanceof AstBreak || $statement instanceof AstContinue) {
				$this->checkLoopExit($statement instanceof AstBreak ? 'break' : 'continue', $inAtomic, $inLoop, $inAnyLoop);
				return false;
			}

			if ($statement instanceof AstReturn && $inAtomic) {
				throw new SemanticException("'return' inside 'atomic { }' is not supported; move it after the block.");
			}

			if ($statement instanceof AstIf) {
				$thenMayRollback = $this->checkBlock($statement->getThenBody(), $inAtomic, $inLoop, $inAnyLoop);
				$elseMayRollback = $this->checkBlock($statement->getElseBody() ?? [], $inAtomic, $inLoop, $inAnyLoop);
				return $thenMayRollback || $elseMayRollback;
			}

			if ($statement instanceof AstWhile || $statement instanceof AstForeach) {
				$this->checkBlock($statement->getBody(), $inAtomic, $inAtomic, true);
				return false;
			}

			if ($statement instanceof AstAtomic) {
				if ($inAtomic) {
					throw new SemanticException("'atomic' blocks can't be nested.");
				}

				// rollback ends the atomic block, not the enclosing path
				$this->checkBlock($statement->getBody(), true, false, $inAnyLoop);
				return false;
			}

			return false;
		}

		/**
		 * Rejects `break`/`continue` outside a loop, or whose loop encloses the atomic block, skipping its cleanup.
		 * @param string $keyword 'break' or 'continue', for error messages
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param bool $inLoop True inside a loop that is itself inside the atomic block
		 * @param bool $inAnyLoop True inside any loop
		 * @return void
		 * @throws SemanticException
		 */
		private function checkLoopExit(string $keyword, bool $inAtomic, bool $inLoop, bool $inAnyLoop): void {
			if (!$inAnyLoop) {
				throw new SemanticException("'{$keyword}' is only valid inside 'while' or 'foreach'.");
			}

			if ($inAtomic && !$inLoop) {
				throw new SemanticException("'{$keyword}' would leave 'atomic { }' without finishing it; move the loop inside the block or the block out of the loop.");
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
