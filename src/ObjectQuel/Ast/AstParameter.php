<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstParameter
	 * Represents a PDO style named parameter
	 */
	class AstParameter extends Ast {
		
		/**
		 * The name of the parameter
		 * @var string
		 */
		protected string $name;

		/** Type of the bound value when the compiler knows it, e.g. 'datetime' for a Unix timestamp */
		private ?string $returnType;
		
		/**
		 * AstNumber constructor.
		 * Initializes the node with a numerical value.
		 * @param string $name The name of the parameter
		 * @param string|null $returnType Type of the bound value, or null when unknown
		 */
		public function __construct(string $name, ?string $returnType = null) {
			$this->name = $name;
			$this->returnType = $returnType;
		}
		
		/**
		 * Retrieves the numerical value stored in this AST node.
		 * @return string The stored numerical value.
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * @return string|null Type of the bound value, or null when unknown
		 */
		public function getReturnType(): ?string {
			return $this->returnType;
		}
		
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->name, $this->returnType);
		}
	}