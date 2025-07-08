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
		protected ?string $aliasPattern;
		private bool $visibleInResult;
		
		/**
		 * AstAlias constructor.
		 * Initializes the node with an alias and the associated expression.
		 * @param string $name The alias name.
		 * @param AstInterface $expression The associated expression.
		 * @param string|null $aliasPattern The pattern used to find the results
		 */
		public function __construct(string $name, AstInterface $expression, ?string $aliasPattern=null) {
			$this->name = $name;
			$this->expression = $expression;
			$this->aliasPattern = $aliasPattern;
			$this->visibleInResult = true;
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
		 * Returns the pattern used to find the information in the SQL result
		 * @return string|null
		 */
		public function getAliasPattern(): ?string {
			return $this->aliasPattern;
		}

		/**
		 * Sets the alias pattern
		 * @return void
		 */
		public function setAliasPattern(?string $aliasPattern): void {
			$this->aliasPattern = $aliasPattern;
		}
		
		/**
		 * Returns true if this field is present in the eventual result, false if not
		 * A field may be invisible if it was automatically added for join purposes
		 * @return true
		 */
		public function isVisibleInResult(): bool {
			return $this->visibleInResult;
		}
		
		/**
		 * Sets the visibleInResult flag
		 * @param bool $visibleInResult
		 * @return void
		 */
		public function setVisibleInResult(bool $visibleInResult): void {
			$this->visibleInResult = $visibleInResult;
		}
		
		
	}
