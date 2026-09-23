<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `define function name (params) returnType { ... }` — one node for both
	 * value-returning and `void` routines; the distinction is semantic, not structural.
	 */
	class AstRoutineDefinition extends Ast implements AstStatement {

		private string $name;
		private string $returnType;

		/** @var AstRoutineParameter[] */
		private array $parameters;

		/** @var AstInterface[] */
		private array $body;

		/**
		 * @param string $name Routine name
		 * @param AstRoutineParameter[] $parameters Parameters in declaration order
		 * @param string $returnType A type name, or the literal "void"
		 * @param AstInterface[] $body Top-level statements of the routine body
		 */
		public function __construct(string $name, array $parameters, string $returnType, array $body) {
			$this->name = $name;
			$this->parameters = $parameters;
			$this->returnType = $returnType;
			$this->body = $body;

			foreach ($this->parameters as $parameter) {
				$parameter->setParent($this);
			}

			foreach ($this->body as $statement) {
				$statement->setParent($this);
			}
		}

		/**
		 * Visits this node, then its parameters, then its body statements.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->parameters as $parameter) {
				$parameter->accept($visitor);
			}

			foreach ($this->body as $statement) {
				$statement->accept($visitor);
			}
		}

		/**
		 * @return string Routine name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return AstRoutineParameter[] Parameters in declaration order
		 */
		public function getParameters(): array {
			return $this->parameters;
		}

		/**
		 * Named apart from AstInterface::getReturnType(), which is an expression's value type.
		 * @return string A type name, or the literal "void"
		 */
		public function getDeclaredReturnType(): string {
			return $this->returnType;
		}

		/**
		 * @return bool True when the declared return type is `void`
		 */
		public function isVoid(): bool {
			return strcasecmp($this->returnType, 'void') === 0;
		}

		/**
		 * @return AstInterface[] Top-level statements of the routine body
		 */
		public function getBody(): array {
			return $this->body;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->cloneArray($this->parameters), $this->returnType, $this->cloneArray($this->body));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
