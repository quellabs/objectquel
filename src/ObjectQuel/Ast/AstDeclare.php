<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A routine-local `type name [= initializer]` declaration. For a `cursor`
	 * local the initializer is an AstRetrieve; otherwise it is an expression.
	 */
	class AstDeclare extends Ast {

		private string $name;
		private string $type;
		private ?AstInterface $initializer;

		/**
		 * @param string $name Local variable name
		 * @param string $type Type name as written in the source
		 * @param AstInterface|null $initializer Initial value, or null when declared without one
		 */
		public function __construct(string $name, string $type, ?AstInterface $initializer) {
			$this->name = $name;
			$this->type = $type;
			$this->initializer = $initializer;
			$this->initializer?->setParent($this);
		}

		/**
		 * Visits this node, then its initializer if present.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->initializer?->accept($visitor);
		}

		/**
		 * @return string Local variable name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return string Type name as written in the source
		 */
		public function getType(): string {
			return $this->type;
		}

		/**
		 * @return bool True when the declared type is `cursor`
		 */
		public function isCursor(): bool {
			return strcasecmp($this->type, 'cursor') === 0;
		}

		/**
		 * @return AstInterface|null Initial value, or null when declared without one
		 */
		public function getInitializer(): ?AstInterface {
			return $this->initializer;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->type, $this->initializer?->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
