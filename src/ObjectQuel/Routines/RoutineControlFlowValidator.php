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
			$this->checkBlock($routine->getBody(), false, 0, 0);

			if (!$routine->returnsNoValue() && !$this->alwaysReturns($routine->getBody())) {
				throw new SemanticException("Not every path through '{$routine->getName()}' ends in a return, but it declares return type '{$routine->getDeclaredReturnType()}'.");
			}
		}

		/**
		 * Checks atomic/rollback/return/break/continue placement in a statement list.
		 * @param AstInterface[] $statements Statements in source order
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param int $loopDepth Number of enclosing loops
		 * @param int $loopsInAtomic Number of enclosing loops entered in the atomic block
		 * @return bool True when some path through the list ends in `rollback`
		 * @throws SemanticException
		 */
		private function checkBlock(array $statements, bool $inAtomic, int $loopDepth, int $loopsInAtomic): bool {
			$mayRollback = false;

			foreach ($statements as $statement) {
				if ($mayRollback) {
					throw new SemanticException("A statement follows 'rollback' on the same path. 'rollback' must be the last statement on its path through the atomic block.");
				}

				$mayRollback = $this->checkStatement($statement, $inAtomic, $loopDepth, $loopsInAtomic);
			}

			return $mayRollback;
		}

		/**
		 * Checks one statement, recursing into nested blocks.
		 * @param AstInterface $statement The statement
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param int $loopDepth Number of enclosing loops
		 * @param int $loopsInAtomic Number of enclosing loops entered in the atomic block
		 * @return bool True when some path through the statement ends in `rollback`
		 * @throws SemanticException
		 */
		private function checkStatement(AstInterface $statement, bool $inAtomic, int $loopDepth, int $loopsInAtomic): bool {
			if ($statement instanceof AstRollback) {
				if (!$inAtomic) {
					throw new SemanticException("'rollback' is only valid inside 'atomic { }'.");
				}

				if ($loopsInAtomic > 0) {
					throw new SemanticException("'rollback' inside a loop would let later iterations run after it; move it out of the loop.");
				}

				return true;
			}

			if ($statement instanceof AstBreak || $statement instanceof AstContinue) {
				$this->checkLoopExit($statement instanceof AstBreak ? 'break' : 'continue', $statement->getLevels(), $inAtomic, $loopDepth, $loopsInAtomic);
				return false;
			}

			if ($statement instanceof AstReturn && $inAtomic) {
				throw new SemanticException("'return' inside 'atomic { }' is not supported; move it after the block.");
			}

			if ($statement instanceof AstIf) {
				$thenMayRollback = $this->checkBlock($statement->getThenBody(), $inAtomic, $loopDepth, $loopsInAtomic);
				$elseMayRollback = $this->checkBlock($statement->getElseBody() ?? [], $inAtomic, $loopDepth, $loopsInAtomic);
				return $thenMayRollback || $elseMayRollback;
			}

			if ($statement instanceof AstWhile || $statement instanceof AstForeach) {
				$this->checkBlock($statement->getBody(), $inAtomic, $loopDepth + 1, $loopsInAtomic + ($inAtomic ? 1 : 0));
				return false;
			}

			if ($statement instanceof AstAtomic) {
				if ($inAtomic) {
					throw new SemanticException("'atomic' blocks can't be nested.");
				}

				// rollback ends the atomic block, not the enclosing path
				$this->checkBlock($statement->getBody(), true, $loopDepth, 0);
				return false;
			}

			return false;
		}

		/**
		 * Rejects invalid loop levels and jumps that leave a loop or atomic block incorrectly.
		 * @param string $keyword 'break' or 'continue', for error messages
		 * @param int|float $levels Parsed loop level
		 * @param bool $inAtomic True inside an `atomic` body
		 * @param int $loopDepth Number of enclosing loops
		 * @param int $loopsInAtomic Number of enclosing loops entered in the atomic block
		 * @return void
		 * @throws SemanticException
		 */
		private function checkLoopExit(string $keyword, int|float $levels, bool $inAtomic, int $loopDepth, int $loopsInAtomic): void {
			if (!is_int($levels)) {
				throw new SemanticException("'{$keyword}' level must be an integer.");
			}

			if ($levels < 1) {
				throw new SemanticException("'{$keyword}' level must be a positive integer.");
			}

			if ($loopDepth === 0) {
				throw new SemanticException("'{$keyword}' is only valid inside 'while' or 'foreach'.");
			}

			if ($levels > $loopDepth) {
				throw new SemanticException("'{$keyword}' level {$levels} exceeds the {$loopDepth} enclosing loop(s).");
			}

			if ($inAtomic && $levels > $loopsInAtomic) {
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