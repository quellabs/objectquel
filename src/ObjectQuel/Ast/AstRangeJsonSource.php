<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\FindIdentifier;
	
	/**
	 * Class AstRange
	 * AstRange klasse is verantwoordelijk voor het definiëren van een bereik in de AST (Abstract Syntax Tree).
	 */
	class AstRangeJsonSource extends AstRange {
		
		// Alias voor het bereik
		protected string $path;
		protected ?string $expression;
		
		/**
		 * AstRange constructor.
		 * @param string $name De naam voor dit bereik.
		 * @param string $path
		 * @param string|null $expression
		 */
		public function __construct(string $name, string $path, ?string $expression=null) {
			parent::__construct($name);
			$this->path = $path;
			$this->expression = $expression;
		}
		
		public function getPath(): string {
			return $this->path;
		}
		
		public function setPath(string $path): void {
			$this->path = $path;
		}
		
		public function getExpression(): ?string {
			return $this->expression;
		}
		
		public function setExpression(?string $expression): void {
			$this->expression = $expression;
		}
	}