<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstString
	 * Represents a string constant in the Abstract Syntax Tree (AST).
	 */
	class AstString extends Ast {
		
		/**
		 * The string value represented by this AST node.
		 * @var string
		 */
		protected string $string;
		protected string $enclosingChar;
		
		/** @var int[] Byte offsets in $string of unescaped * and ? wildcards */
		protected array $wildcardOffsets;
		
		/**
		 * AstString constructor.
		 * Initializes the node with a string value.
		 * @param string $string The string value to store.
		 * @param string $enclosingChar The quote char
		 * @param int[] $wildcardOffsets Byte offsets of unescaped * and ? wildcards in $string
		 */
		public function __construct(string $string, string $enclosingChar, array $wildcardOffsets = []) {
			$this->string = $string;
			$this->enclosingChar = $enclosingChar;
			$this->wildcardOffsets = $wildcardOffsets;
		}
		
		/**
		 * Retrieves the string value stored in this AST node.
		 * @return string The stored string value.
		 */
		public function getValue(): string {
			return $this->string;
		}

		/**
		 * Retrieves the quote char
		 * @return string The stored string value.
		 */
		public function getEnclosingChar(): string {
			return $this->enclosingChar;
		}
		
		/**
		 * Returns the byte offsets of unescaped * and ? wildcards in the value.
		 * @return int[]
		 */
		public function getWildcardOffsets(): array {
			return $this->wildcardOffsets;
		}
		
		/**
		 * Returns true when the value contains at least one unescaped wildcard.
		 * @return bool
		 */
		public function hasWildcards(): bool {
			return $this->wildcardOffsets !== [];
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "string";
		}
		
		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->getValue(), $this->getEnclosingChar(), $this->getWildcardOffsets());
		}
	}