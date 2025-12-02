<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents an IN expression in the AST (e.g., "column IN (value1, value2, value3)")
	 *
	 * This node checks if the identifier's value matches any value in the parameter list.
	 * The expression evaluates to a boolean result.
	 */
	class AstIn extends Ast {
		
		/** @var AstInterface The left-hand side expression to check (e.g., a column or field) */
		protected AstInterface $identifier;
		
		/** @var AstInterface[] The list of values to check against */
		protected array $parameterList;
		
		/**
		 * AstIn constructor
		 * @param AstInterface $identifier The expression to evaluate (left side of IN)
		 * @param AstInterface[] $parameterList Array of expressions to check against (right side of IN)
		 */
		public function __construct(AstInterface $identifier, array $parameterList) {
			$this->identifier = $identifier;
			$this->parameterList = $parameterList;
			
			// Establish parent-child relationships in the AST
			$identifier->setParent($this);
			
			foreach($parameterList as $parameter) {
				$parameter->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			// Visit the left-hand side identifier
			$this->identifier->accept($visitor);
			
			// Visit each parameter in the list
			foreach($this->parameterList as $item) {
				$item->accept($visitor);
			}
		}
		
		/**
		 * Returns all parameters used in the IN-statement
		 * @return AstInterface[] The list of value expressions to check against
		 */
		public function getParameters(): array {
			return $this->parameterList;
		}
		
		/**
		 * Replaces the parameters
		 *
		 * @param AstInterface[] $parameters New list of parameters to check against
		 * @return void
		 */
		public function setParameters(array $parameters): void {
			$this->parameterList = $parameters;
		}
		
		/**
		 * Returns the identifier expression (left side of the IN clause)
		 * @return AstInterface The expression being checked
		 */
		public function getIdentifier(): AstInterface {
			return $this->identifier;
		}
		
		/**
		 * Returns the return type of this node
		 *
		 * IN expressions always evaluate to boolean (true if match found, false otherwise)
		 *
		 * @return string|null Always returns "boolean" for IN expressions
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
		
		/**
		 * Creates a deep clone of this node and all its children
		 *
		 * This is necessary to avoid reference issues when duplicating AST subtrees.
		 * Clones both the identifier and all parameters recursively.
		 *
		 * @return static A new instance with cloned children
		 */
		public function deepClone(): static {
			$clonedIdentifier = $this->identifier->deepClone();
			$clonedParameterList = $this->cloneArray($this->parameterList);
			
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifier, $clonedParameterList);
		}
	}