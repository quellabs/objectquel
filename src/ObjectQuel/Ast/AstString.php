<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstString
	 * Represents a string constant in the Abstract Syntax Tree (AST).
	 */
	class AstString extends Ast {
		
		/**
		 * The string value represented by this AST node.
		 * @var string
		 */
		protected string $string;
		protected string $enclosingChar;
		
		/**
		 * AstString constructor.
		 * Initializes the node with a string value.
		 * @param string $string The string value to store.
		 * @param string $enclosingChar The quote char
		 */
		public function __construct(string $string, string $enclosingChar) {
			$this->string = $string;
			$this->enclosingChar = $enclosingChar;
		}
		
		/**
		 * Retrieves the string value stored in this AST node.
		 * @return string The stored string value.
		 */
		public function getValue(): string {
			return $this->string;
		}

		/**
		 * Retrieves the quote char
		 * @return string The stored string value.
		 */
		public function getEnclosingChar(): string {
			return $this->enclosingChar;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "string";
		}
	}