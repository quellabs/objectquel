<?php
	
	namespace Quellabs\ObjectQuel\Execution\Helpers;
	
	/**
	 * Typed carrier for a regular expression pattern produced by evaluating an AstRegExp node.
	 *
	 * ConditionEvaluator::evaluate() returns this object when it encounters an AstRegExp,
	 * allowing the parent AstExpression handler to distinguish a regexp right-hand side from
	 * a plain string value without inspecting the AST node type after both sides are evaluated.
	 */
	final class RegExpValue {
		
		/**
		 * The regular expression pattern, without delimiters.
		 * @var string
		 */
		private string $pattern;
		
		/**
		 * PCRE-compatible modifier flags (e.g. 'i' for case-insensitive, 'm' for multiline).
		 * @var string
		 */
		private string $flags;
		
		/**
		 * RegExpValue constructor.
		 * @param string $pattern The regex pattern without delimiters.
		 * @param string $flags   Optional PCRE modifier flags.
		 */
		public function __construct(string $pattern, string $flags = '') {
			$this->pattern = $pattern;
			$this->flags   = $flags;
		}
		
		/**
		 * Returns the regex pattern string.
		 * @return string
		 */
		public function getPattern(): string {
			return $this->pattern;
		}
		
		/**
		 * Returns the PCRE modifier flags.
		 * @return string
		 */
		public function getFlags(): string {
			return $this->flags;
		}
		
		/**
		 * Returns a PCRE-ready pattern string including delimiters and flags.
		 * @return string
		 */
		public function toPcre(): string {
			return '/' . $this->pattern . '/' . $this->flags;
		}
	}