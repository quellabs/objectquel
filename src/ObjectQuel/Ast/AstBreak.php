<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `break` — leaves the requested number of enclosing loops.
	 */
	class AstBreak extends Ast {
		
		/** @var int|float Break level */
		private int|float $levels;

		/**
		 * @param int|float $levels Parsed number of enclosing loops to leave
		 */
		public function __construct(int|float $levels = 1) {
			$this->levels = $levels;
		}

		/**
		 * @return int|float Parsed number of enclosing loops to leave
		 */
		public function getLevels(): int|float {
			return $this->levels;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->levels);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
