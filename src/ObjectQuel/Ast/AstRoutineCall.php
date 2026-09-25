<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A call to a stored routine, `name(args)`. Its return type comes from the database catalog, when looked up.
	 */
	class AstRoutineCall extends Ast {

		/** @var string Routine name as written */
		protected string $name;

		/** @var AstInterface[] Arguments in order */
		protected array $arguments;

		/** @var string|null Abstract column type the routine returns, or null when unknown */
		protected ?string $routineReturnType = null;

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
		 * Replaces an argument by identity, preserving its position and setting its parent.
		 * @param AstInterface $oldArgument Argument to replace
		 * @param AstInterface $newArgument Replacement expression
		 * @return bool True when the argument was found and replaced
		 */
		public function replaceArgument(AstInterface $oldArgument, AstInterface $newArgument): bool {
			foreach ($this->arguments as $index => $argument) {
				if ($argument === $oldArgument) {
					$this->arguments[$index] = $newArgument;
					$newArgument->setParent($this);
					return true;
				}
			}

			return false;
		}

		/**
		 * @return string|null Abstract column type the routine returns, or null when unknown
		 */
		public function getRoutineReturnType(): ?string {
			return $this->routineReturnType;
		}

		/**
		 * @param string|null $routineReturnType Abstract column type the routine returns, or null when unknown
		 * @return void
		 */
		public function setRoutineReturnType(?string $routineReturnType): void {
			$this->routineReturnType = $routineReturnType;
		}

		/**
		 * Types the call like a column of the routine's return type.
		 * @return string|null PHP type, or null when the return type is unknown
		 */
		public function getReturnType(): ?string {
			return $this->routineReturnType === null ? null : TypeMapper::phinxTypeToPhpType($this->routineReturnType);
		}

		/**
		 * @return static A copy with cloned arguments and the same return type
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->cloneArray($this->arguments));
			$clone->routineReturnType = $this->routineReturnType;
			return $clone;
		}
	}
