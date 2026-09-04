<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A single `property = value` pair inside an `append`/`replace` statement.
	 * `property` is a plain entity property name (never a dotted chain — unlike
	 * a `where`-clause identifier, an assignment's left-hand side is always
	 * unqualified since it's always relative to the statement's single target
	 * entity). `value` reuses the existing expression AST, so
	 * `region_id = :regionId + 1` parses through the same expression grammar
	 * `where` already uses.
	 */
	class AstAssignment extends Ast {

		private string $property;
		private AstInterface $value;

		/**
		 * AstAssignment constructor.
		 * @param string $property Entity property name being assigned to
		 * @param AstInterface $value Expression AST for the assigned value
		 */
		public function __construct(string $property, AstInterface $value) {
			$this->property = $property;
			$this->value = $value;
			$this->value->setParent($this);
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->value->accept($visitor);
		}

		public function getProperty(): string {
			return $this->property;
		}

		public function getValue(): AstInterface {
			return $this->value;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->property, $this->value->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
