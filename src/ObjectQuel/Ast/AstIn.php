<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class AstIn extends Ast {
		
		protected AstInterface $identifier;
		protected array $parameterList;
		
		/**
		 * AstIn constructor
		 * @param AstInterface $identifier
		 * @param array $parameterList
		 */
		public function __construct(AstInterface $identifier, array $parameterList) {
			$this->identifier = $identifier;
			$this->parameterList = $parameterList;
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			$this->identifier->accept($visitor);
			
			foreach($this->parameterList as $item) {
				$item->accept($visitor);
			}
		}
		
		/**
		 * Returns all parameters used in the IN-statement
		 * @return AstInterface[]
		 */
		public function getParameters(): array {
			return $this->parameterList;
		}
		
		/**
		 * Replaces the parameters
		 * @param array $parameters
		 * @return void
		 */
		public function setParameters(array $parameters): void {
			$this->parameterList = $parameters;
		}
		
		/**
		 * Returns all parameters used in the IN-statement
		 * @return AstInterface
		 */
		public function getIdentifier(): AstInterface {
			return $this->identifier;
		}

		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
	}