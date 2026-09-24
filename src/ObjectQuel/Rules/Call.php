<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for a routine called as a statement: `name(args)`.
	 */
	class Call {

		private Lexer $lexer;

		/**
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parses a complete `name(args)` statement.
		 * @return AstCall
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(): AstCall {
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			if (QueryFunction::isBuiltin($name)) {
				throw new ParserException("'{$name}' is a built-in function, not a routine.");
			}

			$call = (new QueryFunction(new ArithmeticExpression($this->lexer)))->parseRoutineCall($name);

			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}

			return new AstCall($call);
		}
	}
