<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `return expr` — always carries a value; a bare `return` is not supported.
	 */
	class AstReturn extends Ast {

		private AstInterface $value;

		/**
		 * @param AstInterface $value Expression producing the returned value
		 */
		public function __construct(AstInterface $value) {
			$this->value = $value;
			$this->value->setParent($this);
		}

		/**
		 * Visits this node, then the returned value.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->value->accept($visitor);
		}

		/**
		 * @return AstInterface Expression producing the returned value
		 */
		public function getValue(): AstInterface {
			return $this->value;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->value->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
