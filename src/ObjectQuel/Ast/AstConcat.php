<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstConcat
	 * Represents the SQL Concat keyword in the AST (Abstract Syntax Tree).
	 * @package Quellabs\ObjectQuel\ObjectQuel\Ast
	 */
	class AstConcat extends Ast {
		
		/**
		 * Stores the list of parameters for the concat operation.
		 * @var array
		 */
		protected array $parameterList;
		
		/**
		 * AstConcat constructor.
		 * Initializes the node with an array of parameters for the concat operation.
		 * @param array $parameterList The list of parameters.
		 */
		public function __construct(array $parameterList) {
			$this->parameterList = $parameterList;
		}
		
		/**
		 * Method to accept an AstVisitor, part of the Visitor Pattern.
		 * @param AstVisitorInterface $visitor The visitor object.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			// Loop through each parameter and apply the visitor to it.
			foreach($this->parameterList as $item) {
				$item->accept($visitor);
			}
		}
		
		/**
		 * Retrieves the list of parameters used in the concat operation.
		 * @return array The list of parameters.
		 */
		public function getParameters(): array {
			return $this->parameterList;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "string";
		}
	}