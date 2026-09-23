<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A call to a stored routine, `name(args)`. Its return type is unknown.
	 */
	class AstRoutineCall extends Ast {

		/** @var string Routine name as written */
		protected string $name;

		/** @var AstInterface[] Arguments in order */
		protected array $arguments;

		/**
		 * @param string $name Routine name as written
		 * @param AstInterface[] $arguments Arguments in order
		 */
		public function __construct(string $name, array $arguments) {
			$this->name = $name;
			$this->arguments = $arguments;

			foreach ($arguments as $argument) {
				$argument->setParent($this);
			}
		}

		/**
		 * Visits this node, then each argument.
		 * @param AstVisitorInterface $visitor The visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->arguments as $argument) {
				$argument->accept($visitor);
			}
		}

		/**
		 * @return string Routine name as written
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return AstInterface[] Arguments in order
		 */
		public function getArguments(): array {
			return $this->arguments;
		}

		/**
		 * @return static A copy with cloned arguments
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->name, $this->cloneArray($this->arguments));
		}
	}
