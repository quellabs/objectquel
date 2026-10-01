<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `[range of ...] define function name (params) returnType { ... }` —
	 * one node for both value-returning and `void` routines; the
	 * distinction is semantic, not structural.
	 *
	 * Ranges are declared ahead of `define`, like any other top-level
	 * statement (see Parser::dispatchStatement()), not inside the body —
	 * same reasoning as an import list sitting outside a function: a
	 * routine's data sources are static and known before it runs, so they
	 * read better predeclared than scattered through the body.
	 *
	 * May be preceded by compiler directives, e.g. `@ignoreSoftDelete true
	 * define function ...`, same syntax as ahead of a top-level statement
	 * (see Parser::parseDirectives()). The directive applies to the whole
	 * routine body — every `delete`/`retrieve` inside it — rather than to
	 * one statement, since the routine language has no per-statement
	 * directive syntax of its own (see RoutineStatementCompiler).
	 */
	class AstRoutineDefinition extends Ast implements AstStatement {

		/** @var array<string, mixed> Compiler directives parsed ahead of `define function`, e.g. @ignoreSoftDelete */
		private array $directives;

		/** @var AstRange[] Ranges declared ahead of `define function` */
		private array $ranges;

		private string $name;
		private string $returnType;

		/** @var AstRoutineParameter[] */
		private array $parameters;

		/** @var AstInterface[] */
		private array $body;

		/**
		 * @param array<string, mixed> $directives Compiler directives, e.g. @ignoreSoftDelete
		 * @param AstRange[] $ranges Ranges declared ahead of `define function`
		 * @param string $name Routine name
		 * @param AstRoutineParameter[] $parameters Parameters in declaration order
		 * @param string $returnType A type name, or the literal "void"
		 * @param AstInterface[] $body Top-level statements of the routine body
		 */
		public function __construct(array $directives, array $ranges, string $name, array $parameters, string $returnType, array $body) {
			$this->directives = $directives;
			$this->ranges = $ranges;
			$this->name = $name;
			$this->parameters = $parameters;
			$this->returnType = $returnType;
			$this->body = $body;

			foreach ($this->ranges as $range) {
				$range->setParent($this);
			}

			foreach ($this->parameters as $parameter) {
				$parameter->setParent($this);
			}

			foreach ($this->body as $statement) {
				$statement->setParent($this);
			}
		}

		/**
		 * Visits this node, then its ranges, then its parameters, then its body statements.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->ranges as $range) {
				$range->accept($visitor);
			}

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
		 * Returns all compiler directives for this routine.
		 * @return array<string, mixed>
		 */
		public function getDirectives(): array {
			return $this->directives;
		}

		/**
		 * Returns a specific compiler directive value. Directive names are
		 * case-insensitive — see Parser::parseDirectives().
		 * @param string $name The directive name
		 * @return mixed The directive value or null if not found
		 */
		public function getDirective(string $name): mixed {
			return $this->directives[strtolower($name)] ?? null;
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
		 * @return AstRange[] Ranges declared ahead of `define function`
		 */
		public function getRanges(): array {
			return $this->ranges;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->directives, $this->cloneArray($this->ranges), $this->name, $this->cloneArray($this->parameters), $this->returnType, $this->cloneArray($this->body));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
