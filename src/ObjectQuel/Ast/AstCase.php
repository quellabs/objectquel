<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * A CASE expression evaluates conditions and returns the corresponding expression
	 * when the condition is true. This follows SQL CASE WHEN syntax patterns.
	 */
	class AstCase extends Ast {

		/** @var AstInterface The conditions to evaluate (typically WHEN clauses) */
		private AstInterface $conditions;
		
		/** @var AstInterface The expression to return when conditions are met */
		private AstInterface $expression;
		
		/**
		 * Constructs a new CASE AST node
		 * @param AstInterface $conditions The conditional logic (WHEN clauses)
		 * @param AstInterface $expression The result expression to evaluate/return
		 */
		public function __construct(AstInterface $conditions, AstInterface $expression) {
			$this->conditions = $conditions;
			$this->expression = $expression;
			
			// Establish parent-child relationships for tree traversal
			$this->conditions->setParent($this);
			$this->expression->setParent($this);
		}
		
		/**
		 * Implements visitor pattern for AST traversal
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->conditions->accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Gets the conditions node (WHEN clauses)
		 * @return AstInterface|null The conditions AST node
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Gets the expression node (result value)
		 * @return AstInterface|null The expression AST node
		 */
		public function getExpression(): ?AstInterface {
			return $this->expression;
		}
		
		/**
		 * Creates a deep copy of this CASE node and all its children
		 * @return static A completely independent copy of this node
		 */
		public function deepClone(): static {
			// Recursively clone child nodes to ensure complete independence
			$clonedConditions = $this->conditions->deepClone();
			$clonedExpression = $this->expression->deepClone();
			
			// Create new instance with cloned children
			// @phpstan-ignore-next-line new.static
			return new static($clonedConditions, $clonedExpression);
		}
	}