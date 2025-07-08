<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstNumber
	 * Represents a numerical constant in the Abstract Syntax Tree (AST).
	 */
	class AstNumber extends Ast {
		
		/**
		 * The numerical value represented by this AST node.
		 * @var string
		 */
		protected string $number;
		
		/**
		 * AstNumber constructor.
		 * Initializes the node with a numerical value.
		 * @param string $number The numerical value to store.
		 */
		public function __construct(string $number) {
			$this->number = $number;
		}
		
		/**
		 * Retrieves the numerical value stored in this AST node.
		 * @return string The stored numerical value.
		 */
		public function getValue(): string {
			return $this->number;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return str_contains($this->number, ".") ? "float" : "integer";
		}
	}