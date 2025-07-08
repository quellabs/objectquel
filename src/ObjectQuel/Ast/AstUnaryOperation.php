<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class AstUnaryOperation extends Ast {
		private AstInterface $expression;
		private string $operator;
		
		public function __construct(AstInterface $expression, string $operator) {
			$this->expression = $expression;
			$this->operator = $operator;
			
			$expression->setParent($this);
		}
		
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		public function getOperator(): string {
			return $this->operator;
		}
		
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
	}
