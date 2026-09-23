<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `name = expr` assigning to a routine parameter or scalar local — distinct
	 * from AstAssignment, which assigns an entity property inside append/replace.
	 */
	class AstVariableAssignment extends Ast {

		private string $name;
		private AstInterface $value;

		/**
		 * @param string $name Name of the variable being assigned
		 * @param AstInterface $value Expression producing the new value
		 */
		public function __construct(string $name, AstInterface $value) {
			$this->name = $name;
			$this->value = $value;
			$this->value->setParent($this);
		}

		/**
		 * Visits this node, then the assigned value.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->value->accept($visitor);
		}

		/**
		 * @return string Name of the variable being assigned
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return AstInterface Expression producing the new value
		 */
		public function getValue(): AstInterface {
			return $this->value;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->value->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
