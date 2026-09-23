<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeleteCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplaceCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses the `{ ... }` statement blocks of a routine body. Placement and
	 * name rules (top-level-only declarations, declare-before-use, etc.) are
	 * semantic checks, not enforced here.
	 */
	class RoutineBlock {

		/** Words that start a procedural statement; recognized by text, like other contextual keywords. */
		public const array STATEMENT_KEYWORDS = ['if', 'else', 'while', 'foreach', 'return', 'begin', 'abort', 'replace', 'delete', 'call'];

		private Lexer $lexer;
		private Range $rangeRule;
		private LogicalExpression $expressionRule;

		/** @var AstRange[] Ranges declared so far, in source order; handed to embedded statements */
		private array $ranges = [];

		/**
		 * @param Lexer $lexer Lexer over the routine source
		 * @param EntityStore $entityStore Resolves `range of x is Entity` declarations
		 */
		public function __construct(Lexer $lexer, EntityStore $entityStore) {
			$this->lexer = $lexer;
			$this->rangeRule = new Range($lexer, $entityStore);
			$this->expressionRule = new LogicalExpression($lexer);
		}

		/**
		 * Parses `{ statement* }`.
		 * @return AstInterface[] Statements in source order
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parseBlock(): array {
			$this->expect(Token::CurlyBraceOpen, "'{'");

			$statements = [];

			while ($this->lexer->lookahead() !== Token::CurlyBraceClose) {
				if ($this->lexer->lookahead() === Token::Eof) {
					throw new ParserException("Unexpected end of input: missing '}'");
				}

				$statements[] = $this->parseStatement();
				$this->consumeOptionalSemicolon();
			}

			$this->lexer->match(Token::CurlyBraceClose);

			return $statements;
		}

		/**
		 * Parses one statement, dispatching on its leading token.
		 * @return AstInterface The parsed statement node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseStatement(): AstInterface {
			switch ($this->lexer->lookahead()) {
				case Token::Range:
					$range = $this->rangeRule->parse();
					$this->ranges[] = $range;
					return new AstRangeDeclaration($range);

				case Token::Retrieve:
					return $this->parseRetrieve();

				case Token::Append:
					return (new Append($this->lexer))->parse($this->ranges);

				case Token::Identifier:
					return $this->parseIdentifierStatement();

				default:
					$tokenName = Token::toString($this->lexer->lookahead()) ?: 'unknown';
					throw new ParserException("Unexpected token '{$tokenName}' in routine body on line {$this->lexer->getLineNumber()}");
			}
		}

		/**
		 * Dispatches statements that begin with an identifier: contextual
		 * statement keywords first, then `type name` declarations and `name =` assignments.
		 * @return AstInterface The parsed statement node
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseIdentifierStatement(): AstInterface {
			$keyword = strtolower($this->lexer->peek()->getStringValue());

			switch ($keyword) {
				case 'if':
					return $this->parseIf();

				case 'while':
					$this->lexer->matchKeyword('while');
					$condition = $this->expressionRule->parse();
					return new AstWhile($condition, $this->parseBlock());

				case 'foreach':
					$this->lexer->matchKeyword('foreach');
					$cursorName = $this->lexer->match(Token::Identifier)->getStringValue();
					return new AstForeach($cursorName, $this->parseBlock());

				case 'return':
					$this->lexer->matchKeyword('return');
					return new AstReturn($this->expressionRule->parse());

				case 'begin':
					$this->lexer->matchKeyword('begin');
					$this->lexer->matchKeyword('transaction');
					return new AstBeginTransaction($this->parseBlock());

				case 'abort':
					$this->lexer->matchKeyword('abort');
					return new AstAbort();

				case 'replace':
					return $this->parseReplace();

				case 'delete':
					return $this->parseDelete();

				case 'call':
					return (new Call($this->lexer))->parse();

				case 'else':
					throw new ParserException("'else' without a preceding 'if' on line {$this->lexer->getLineNumber()}");
			}

			return match ($this->lexer->peekNext()) {
				Token::Identifier => $this->parseDeclaration(),
				Token::Equals => $this->parseAssignment(),
				default => throw new ParserException("Expected a declaration, assignment, or statement keyword on line {$this->lexer->getLineNumber()}"),
			};
		}

		/**
		 * Parses `if condition { ... } [else { ... }]`.
		 * @return AstIf
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseIf(): AstIf {
			$this->lexer->matchKeyword('if');
			$condition = $this->expressionRule->parse();
			$thenBody = $this->parseBlock();

			$elseBody = $this->lexer->optionalMatchKeyword('else') !== null ? $this->parseBlock() : null;

			return new AstIf($condition, $thenBody, $elseBody);
		}

		/**
		 * `type name [= initializer]` — a `retrieve` initializer is parsed as a
		 * statement, anything else as an expression. Whether the type allows
		 * that initializer is checked later, not here.
		 * @return AstDeclare
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseDeclaration(): AstDeclare {
			$type = $this->lexer->match(Token::Identifier)->getStringValue();
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			if (!$this->lexer->optionalMatch(Token::Equals)) {
				return new AstDeclare($name, $type, null);
			}

			$initializer = $this->lexer->lookahead() === Token::Retrieve
				? $this->parseRetrieve()
				: $this->expressionRule->parse();

			return new AstDeclare($name, $type, $initializer);
		}

		/**
		 * Parses `name = expr`.
		 * @return AstVariableAssignment
		 * @throws LexerException|ParserException
		 */
		private function parseAssignment(): AstVariableAssignment {
			$name = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->lexer->match(Token::Equals);

			return new AstVariableAssignment($name, $this->expressionRule->parse());
		}

		/**
		 * Target-list entries without an alias are named after the bare
		 * property (`u.id` -> `id`), so a cursor row exposes them as `cursor.id`.
		 * @return AstInterface The parsed AstRetrieve
		 * @throws LexerException|ParserException
		 */
		private function parseRetrieve(): AstInterface {
			return (new Retrieve($this->lexer, true))->parse([], $this->ranges);
		}

		/**
		 * `replace <range> (...) where ...` when the name is a declared range,
		 * otherwise the current-tuple form `replace cursorName (...)`.
		 * @return AstInterface AstReplace or AstReplaceCurrent
		 * @throws LexerException|ParserException
		 */
		private function parseReplace(): AstInterface {
			if ($this->targetIsDeclaredRange('replace')) {
				return (new Replace($this->lexer))->parse($this->ranges);
			}

			$this->lexer->matchKeyword('replace');
			$cursorName = $this->lexer->match(Token::Identifier)->getStringValue();

			return new AstReplaceCurrent($cursorName, (new Replace($this->lexer))->parseAssignments());
		}

		/**
		 * `delete <range> where ...` when the name is a declared range,
		 * otherwise the current-tuple form `delete cursorName`.
		 * @return AstInterface AstDelete or AstDeleteCurrent
		 * @throws LexerException|ParserException
		 */
		private function parseDelete(): AstInterface {
			if ($this->targetIsDeclaredRange('delete')) {
				return (new Delete($this->lexer))->parse([], $this->ranges);
			}

			$this->lexer->matchKeyword('delete');

			return new AstDeleteCurrent($this->lexer->match(Token::Identifier)->getStringValue());
		}

		/**
		 * Peeks past `$keyword` to see whether its target names a declared range.
		 * @param string $keyword The statement keyword (`replace` or `delete`)
		 * @return bool True when the target is a declared range name
		 * @throws LexerException
		 */
		private function targetIsDeclaredRange(string $keyword): bool {
			$state = $this->lexer->saveState();

			try {
				$this->lexer->matchKeyword($keyword);
				$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			} finally {
				$this->lexer->restoreState($state);
			}

			foreach ($this->ranges as $range) {
				if ($range->getName() === $targetName) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Consumes a token of the given type, or throws a ParserException naming what was expected.
		 * @param int $tokenType Expected Token type
		 * @param string $description Human-readable token description for the error message
		 * @return void
		 * @throws LexerException|ParserException
		 */
		private function expect(int $tokenType, string $description): void {
			if ($this->lexer->lookahead() !== $tokenType) {
				throw new ParserException("Expected {$description} on line {$this->lexer->getLineNumber()}");
			}

			$this->lexer->match($tokenType);
		}

		/**
		 * Consumes a trailing `;` if present.
		 * @return void
		 * @throws LexerException
		 */
		private function consumeOptionalSemicolon(): void {
			$this->lexer->optionalMatch(Token::Semicolon);
		}
	}
