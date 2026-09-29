<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `return expr`, or a bare `return` with no value — only valid in a void routine.
	 */
	class AstReturn extends Ast {

		private ?AstInterface $value;

		/**
		 * @param AstInterface|null $value Expression producing the returned value, or null for a bare `return`
		 */
		public function __construct(?AstInterface $value = null) {
			$this->value = $value;
			$this->value?->setParent($this);
		}

		/**
		 * Visits this node, then the returned value, if any.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->value?->accept($visitor);
		}

		/**
		 * @return AstInterface|null Expression producing the returned value, or null for a bare `return`
		 */
		public function getValue(): ?AstInterface {
			return $this->value;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->value?->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
