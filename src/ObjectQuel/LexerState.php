<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * LexerState class
	 *
	 * Represents the current state of the lexer during tokenization process.
	 * This class maintains position tracking, line numbers, and token information
	 * to enable context-aware lexical analysis and lookahead functionality.
	 */
	class LexerState {
		
		/** @var int Current position in the input stream */
		private int $pos;
		
		/** @var int Previous position in the input stream */
		private int $previousPos;
		
		/** @var int Position before the previous position, for backtracking */
		private int $previousPreviousPos;
		
		/** @var int Current line number for error reporting */
		private int $lineNumber;
		
		/** @var Token The next token to be consumed */
		private Token $next_token;
		
		/** @var Token Lookahead token for predictive parsing */
		private Token $lookahead;
		
		/**
		 * Constructs a new LexerState with the given parameters
		 * @param int $pos Current position in the input
		 * @param int $previousPos Previous position in the input
		 * @param int $previousPreviousPos Position before the previous position
		 * @param int $lineNumber Current line number for error reporting
		 * @param Token $next_token The next token to be consumed
		 * @param Token $lookahead Lookahead token for predictive parsing
		 */
		public function __construct(
			int    $pos,
			int    $previousPos,
			int    $previousPreviousPos,
			int    $lineNumber,
			Token  $next_token,
			Token  $lookahead,
		) {
			$this->pos = $pos;
			$this->previousPos = $previousPos;
			$this->previousPreviousPos = $previousPreviousPos;
			$this->lineNumber = $lineNumber;
			$this->next_token = $next_token;
			$this->lookahead = $lookahead;
		}
		
		/**
		 * Gets the current position in the input stream
		 * @return int Current position
		 */
		public function getPos(): int {
			return $this->pos;
		}
		
		/**
		 * Gets the previous position in the input stream
		 * @return int Previous position
		 */
		public function getPreviousPos(): int {
			return $this->previousPos;
		}
		
		/**
		 * Gets the position before the previous position
		 * Useful for multi-level backtracking operations
		 * @return int Position before the previous position
		 */
		public function getPreviousPreviousPos(): int {
			return $this->previousPreviousPos;
		}
		
		/**
		 * Gets the current line number
		 * Useful for error reporting and debugging
		 * @return int Current line number
		 */
		public function getLineNumber(): int {
			return $this->lineNumber;
		}
		
		/**
		 * Gets the next token to be consumed
		 * @return Token The next token
		 */
		public function getNextToken(): Token {
			return $this->next_token;
		}
		
		/**
		 * Gets the lookahead token
		 * Used for predictive parsing and decision making
		 * @return Token The lookahead token
		 */
		public function getLookahead(): Token {
			return $this->lookahead;
		}
	}