<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `replace cursorName (attr = value, ...)` inside `foreach cursorName { }` —
	 * updates the row currently being iterated (lowers to `WHERE CURRENT OF`).
	 */
	class AstReplaceCurrent extends Ast {

		private string $cursorName;

		/** @var AstAssignment[] */
		private array $assignments;

		/**
		 * @param string $cursorName Name of the enclosing loop's `cursor` local
		 * @param AstAssignment[] $assignments Property assignments applied to the current row
		 */
		public function __construct(string $cursorName, array $assignments) {
			$this->cursorName = $cursorName;
			$this->assignments = $assignments;

			foreach ($this->assignments as $assignment) {
				$assignment->setParent($this);
			}
		}

		/**
		 * Visits this node, then its assignments.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->assignments as $assignment) {
				$assignment->accept($visitor);
			}
		}

		/**
		 * @return string Name of the enclosing loop's `cursor` local
		 */
		public function getCursorName(): string {
			return $this->cursorName;
		}

		/**
		 * @return AstAssignment[] Property assignments applied to the current row
		 */
		public function getAssignments(): array {
			return $this->assignments;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->cursorName, $this->cloneArray($this->assignments));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
