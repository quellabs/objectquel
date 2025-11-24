<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstRangeJsonSource
	 * Represents a range in the AST that sources data from a JSON path expression.
	 * Extends AstRange to provide JSON-specific range functionality.
	 */
	class AstRangeJsonSource extends AstRange {
		
		/**
		 * JSON path expression used to locate data within a JSON structure
		 * @var string
		 */
		protected string $path;
		
		/**
		 * Optional filter/query expression to apply to the JSON data
		 * @var string|null
		 */
		protected ?string $expression;
		
		/**
		 * Constructs a new JSON source range.
		 * @param string $name The identifier/alias for this range
		 * @param string $path The JSON path expression (e.g., "$.users[*].name")
		 * @param string|null $expression Optional filter expression to refine the selection
		 */
		public function __construct(string $name, string $path, ?string $expression = null) {
			parent::__construct($name);
			$this->path = $path;
			$this->expression = $expression;
		}
		
		/**
		 * Retrieves the JSON path expression.
		 * @return string The path used to navigate the JSON structure
		 */
		public function getPath(): string {
			return $this->path;
		}
		
		/**
		 * Sets the JSON path expression.
		 * @param string $path The new path value
		 */
		public function setPath(string $path): void {
			$this->path = $path;
		}
		
		/**
		 * Retrieves the optional filter expression.
		 * @return string|null The filter expression, or null if not set
		 */
		public function getExpression(): ?string {
			return $this->expression;
		}
		
		/**
		 * Sets the filter expression.
		 * @param string|null $expression The filter to apply, or null to clear
		 */
		public function setExpression(?string $expression): void {
			$this->expression = $expression;
		}
		
		/**
		 * Creates a deep clone of this range node.
		 * @return static A new instance with the same property values
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->getName(), $this->getPath(), $this->getExpression());
		}
	}