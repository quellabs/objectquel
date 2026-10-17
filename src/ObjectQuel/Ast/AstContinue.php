<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `continue` — starts the next iteration of the requested enclosing loop.
	 */
	class AstContinue extends Ast {
		
		/** @var int|float Continue level */
		private int|float $levels;
		
		/**
		 * @param int|float $levels Parsed number of enclosing loops to continue
		 */
		public function __construct(int|float $levels = 1) {
			$this->levels = $levels;
		}

		/**
		 * @return int|float Parsed number of enclosing loops to continue
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
