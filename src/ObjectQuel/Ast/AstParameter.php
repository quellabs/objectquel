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
		
		/**
		 * AstNumber constructor.
		 * Initializes the node with a numerical value.
		 * @param string $name The name of the parameter
		 */
		public function __construct(string $name) {
			$this->name = $name;
		}
		
		/**
		 * Retrieves the numerical value stored in this AST node.
		 * @return string The stored numerical value.
		 */
		public function getName(): string {
			return $this->name;
		}
	}