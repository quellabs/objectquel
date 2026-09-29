<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `range of alias is Entity` statement inside a routine body, kept in
	 * statement order so its placement and name can be validated.
	 */
	class AstRangeDeclaration extends Ast {

		private AstRange $range;

		/**
		 * @param AstRange $range The declared range; not re-parented, as embedded statements share it
		 */
		public function __construct(AstRange $range) {
			$this->range = $range;
		}

		/**
		 * Visits this node, then the declared range.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->range->accept($visitor);
		}

		/**
		 * @return AstRange The declared range
		 */
		public function getRange(): AstRange {
			return $this->range;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->range->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
