<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineDefinition;

	/**
	 * Entry point for routine source files (EQUEL): exactly one
	 * `define function ...` per source. Kept apart from Parser, which
	 * handles ad hoc single-statement queries.
	 */
	class ProcedureParser {

		private Lexer $lexer;
		private RoutineDefinition $routineRule;

		/**
		 * Initializes the routine parser with its lexer.
		 * @param Lexer $lexer Lexer over the routine source
		 * @param EntityStore $entityStore Resolves `range of x is Entity` declarations
		 */
		public function __construct(Lexer $lexer, EntityStore $entityStore) {
			$this->lexer = $lexer;
			$this->routineRule = new RoutineDefinition($lexer, $entityStore);
		}

		/**
		 * Parses the single routine definition the source must contain.
		 * @return AstRoutineDefinition
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(): AstRoutineDefinition {
			if (!$this->lexer->peekKeyword('define')) {
				throw new ParserException("A routine source must start with 'define function'");
			}

			$routine = $this->routineRule->parse();

			if ($this->lexer->lookahead() !== Token::Eof) {
				throw new ParserException("A routine source contains exactly one routine definition; unexpected input after '{$routine->getName()}' on line {$this->lexer->getLineNumber()}");
			}

			return $routine;
		}
	}
