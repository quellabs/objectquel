<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstAlias
	 * Represents an alias node in the Abstract Syntax Tree (AST).
	 */
	class AstAlias extends Ast {
		
		protected string $name;
		protected AstInterface $expression;
		private bool $visibleInResult;
		
		/**
		 * AstAlias constructor.
		 * Initializes the node with an alias and the associated expression.
		 * @param string $name The alias name.
		 * @param AstInterface $expression The associated expression.
		 */
		public function __construct(string $name, AstInterface $expression) {
			$this->name = $name;
			$this->expression = $expression;
			$this->visibleInResult = true;
			
			$this->expression->setParent($this);
		}
		
		/**
		 * Accepts a visitor to traverse the AST.
		 * @param AstVisitorInterface $visitor The visitor object.
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Retrieves the alias name stored in this AST node.
		 * @return string The stored alias name.
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Retrieves the associated expression stored in this AST node.
		 * @return AstInterface The stored expression.
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Retrieves the associated expression stored in this AST node.
		 * @param AstInterface $expression
		 * @return void
		 */
		public function setExpression(AstInterface $expression): void {
			$this->expression = $expression;
		}
		
		/**
		 * Returns true if this field is present in the eventual result, false if not
		 * A field may be invisible if it was automatically added for join purposes
		 * @return bool
		 */
		public function showInResult(): bool {
			return $this->visibleInResult;
		}
		
		/**
		 * Sets the visibleInResult flag
		 * @param bool $visibleInResult
		 * @return void
		 */
		public function setShowInResult(bool $visibleInResult): void {
			$this->visibleInResult = $visibleInResult;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Create new instance with cloned expression
			$clonedExpression = $this->expression->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $clonedExpression);
			
			// Set the parent relationship
			$clonedExpression->setParent($clone);
			
			// Copy other primitive properties
			$clone->visibleInResult = $this->visibleInResult;
	
			// Return the clone
			return $clone;
		}
	}