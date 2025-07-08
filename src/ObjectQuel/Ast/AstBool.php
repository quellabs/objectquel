<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstBool
	 * Represents a boolean constant in the Abstract Syntax Tree (AST).
	 */
	class AstBool extends Ast {
		
		/**
		 * The boolean value represented by this AST node.
		 * @var bool
		 */
		protected bool $bool;
		
		/**
		 * AstBool constructor.
		 * Initializes the node with a boolean value.
		 * @param bool $bool The boolean value to store.
		 */
		public function __construct(bool $bool) {
			$this->bool = $bool;
		}
		
		/**
		 * Retrieves the boolean value stored in this AST node.
		 * @return bool The stored boolean value.
		 */
		public function getValue(): bool {
			return $this->bool;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
	}