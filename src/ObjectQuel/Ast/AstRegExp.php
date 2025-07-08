<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Class AstRegExp
	 * 
	 * Represents a regular expression constant in the Abstract Syntax Tree (AST).
	 * This class handles the storage and retrieval of regular expression patterns
	 * along with their associated flags.
	 *
	 * @package Quellabs\ObjectQuel\ObjectQuel\Ast
	 */
	class AstRegExp extends Ast {
		
		/**
		 * The string value representing the regular expression pattern.
		 * This contains the actual pattern without the delimiters.
		 * @var string
		 */
		protected string $string;
		
		/**
		 * The flags used in the regular expression pattern.
		 * Common flags include: 'i' (case-insensitive), 'g' (global),
		 * 'm' (multi-line), 's' (dot-all), 'u' (unicode), etc.
		 * @var string
		 */
		protected string $flags;
		
		/**
		 * AstRegExp constructor.
		 * Initializes a new regular expression AST node with the specified pattern and flags.
		 * @param string $string The regular expression pattern string.
		 * @param string $flags The regular expression flags (optional).
		 *                      Default is an empty string, meaning no flags are set.
		 */
		public function __construct(string $string, string $flags='') {
			$this->string = $string;
			$this->flags = $flags;
		}
		
		/**
		 * Retrieves the regular expression pattern stored in this AST node.
		 * @return string The stored regular expression pattern.
		 */
		public function getValue(): string {
			return $this->string;
		}
		
		/**
		 * Retrieves the flags associated with this regular expression.
		 * These flags modify the behavior of the regular expression pattern.
		 * @return string The stored regular expression flags.
		 */
		public function getFlags(): string {
			return $this->flags;
		}
	}