<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRollback;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
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
		public const array STATEMENT_KEYWORDS = ['if', 'else', 'elseif', 'while', 'foreach', 'return', 'atomic', 'rollback', 'break', 'continue', 'replace', 'delete', 'retrieve', 'append'];

		/** Compound-assignment operator tokens (the `x` in `x=`) and the arithmetic operator each applies */
		private const array COMPOUND_OPERATORS = [Token::Plus => '+', Token::Minus => '-', Token::Star => '*', Token::Slash => '/'];

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

				case Token::Identifier:
					return $this->parseIdentifierStatement();
				
				case Token::Plus:
				case Token::Minus:
					if ($this->lexer->peekIncrementOperator()) {
						throw new ParserException("Write '{$this->incrementOperator()}' after the variable name, not before it, on line {$this->lexer->getLineNumber()}");
					}
					// A single sign falls through to the generic error

				default:
					$tokenName = Token::toString($this->lexer->lookahead()) ?: 'unknown';
					throw new ParserException("Unexpected token '{$tokenName}' in routine body on line {$this->lexer->getLineNumber()}");
			}
		}

		/**
		 * Dispatches statements that begin with an identifier: contextual
		 * statement keywords first, then `type name` declarations, `name =` assignments, `name++`/`name += expr` (also `-=`, `*=`, `/=`) and `name(args)` procedure calls.
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
					$condition = $this->parseCondition('while');
					return new AstWhile($condition, $this->parseBlock());

				case 'foreach':
					$this->lexer->matchKeyword('foreach');
					[$cursorName, $rowName] = $this->parseForeachClause();
					return new AstForeach($cursorName, $rowName, $this->parseBlock());

				case 'return':
					$this->lexer->matchKeyword('return');

					if (in_array($this->lexer->lookahead(), [Token::CurlyBraceClose, Token::Semicolon], true)) {
						return new AstReturn();
					}

					return new AstReturn($this->expressionRule->parse());

				case 'atomic':
					$this->lexer->matchKeyword('atomic');
					return new AstAtomic($this->parseBlock());

				case 'rollback':
					$this->lexer->matchKeyword('rollback');
					return new AstRollback();

				case 'break':
					$this->lexer->matchKeyword('break');
					return new AstBreak();

				case 'continue':
					$this->lexer->matchKeyword('continue');
					return new AstContinue();

				case 'replace':
					return $this->parseReplace();

				case 'delete':
					return $this->parseDelete();

				case 'retrieve':
					return $this->parseRetrieve();

				case 'append':
					return (new Append($this->lexer))->parse($this->ranges);

				case 'else':
				case 'elseif':
					throw new ParserException("'{$keyword}' without a preceding 'if' on line {$this->lexer->getLineNumber()}");
			}

			return match ($this->lexer->peekNext()) {
				Token::Identifier => $this->parseDeclaration(),
				Token::Equals => $this->parseAssignment(),
				Token::Plus, Token::Minus, Token::Star, Token::Slash => $this->parseCompoundAssignment(),
				Token::ParenthesesOpen => (new Call($this->lexer))->parse(),
				default => throw new ParserException("Expected a declaration, assignment, call, or statement keyword on line {$this->lexer->getLineNumber()}"),
			};
		}

		/**
		 * Parses the parenthesized condition of an if, elseif or while.
		 * @param string $keyword Statement word, for error messages
		 * @return AstInterface
		 * @throws LexerException|ParserException
		 */
		private function parseCondition(string $keyword): AstInterface {
			$this->expect(Token::ParenthesesOpen, "'(' after '{$keyword}'");
			$condition = $this->expressionRule->parse();
			$this->expect(Token::ParenthesesClose, "')' to close the '{$keyword}' condition");
			return $condition;
		}

		/**
		 * Parses `(cursorName as rowName)`, mirroring if/while's own parenthesized clause.
		 * @return array{string, string} Cursor name and the row binding it's given
		 * @throws LexerException|ParserException
		 */
		private function parseForeachClause(): array {
			$this->expect(Token::ParenthesesOpen, "'(' after 'foreach'");
			$cursorName = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->lexer->matchKeyword('as');
			$rowName = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->expect(Token::ParenthesesClose, "')' to close the 'foreach' clause");
			return [$cursorName, $rowName];
		}

		/**
		 * Parses `if (condition) { ... }` with any `elseif`/`else if (condition) { ... }` and a final `else { ... }`; an elseif becomes an if nested in the else body.
		 * @param string $keyword 'if', or 'elseif' for a later branch
		 * @return AstIf
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseIf(string $keyword = 'if'): AstIf {
			$this->lexer->matchKeyword($keyword);
			$condition = $this->parseCondition($keyword);
			$thenBody = $this->parseBlock();

			if ($this->lexer->peekKeyword('elseif')) {
				return new AstIf($condition, $thenBody, [$this->parseIf('elseif')]);
			}

			if ($this->lexer->optionalMatchKeyword('else') === null) {
				return new AstIf($condition, $thenBody, null);
			}

			if ($this->lexer->peekKeyword('if')) {
				return new AstIf($condition, $thenBody, [$this->parseIf()]);
			}

			return new AstIf($condition, $thenBody, $this->parseBlock());
		}

		/**
		 * Parses `type name [= initializer]`; a `retrieve` initializer is a statement, anything else an expression.
		 * @return AstDeclare
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseDeclaration(): AstDeclare {
			$type = $this->lexer->match(Token::Identifier)->getStringValue();
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			if (!$this->lexer->optionalMatch(Token::Equals)) {
				return new AstDeclare($name, $type, null);
			}

			$initializer = $this->lexer->peekKeyword('retrieve')
				? $this->parseRetrieve()
				: $this->expressionRule->parse();

			return new AstDeclare($name, $type, $initializer);
		}

		/**
		 * Parses `name = expr` or `name = retrieve (...)`; whether `name` may take a
		 * retrieve (a cursor) or not (everything else) is a semantic check, not enforced here.
		 * @return AstVariableAssignment
		 * @throws LexerException|ParserException
		 */
		private function parseAssignment(): AstVariableAssignment {
			$name = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->lexer->match(Token::Equals);

			$value = $this->lexer->peekKeyword('retrieve')
				? $this->parseRetrieve()
				: $this->expressionRule->parse();

			return new AstVariableAssignment($name, $value);
		}

		/**
		 * Parses `name++`, `name--` and `name op= expr` (`+=`, `-=`, `*=`, `/=`) as `name = name op value`.
		 * @return AstVariableAssignment
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseCompoundAssignment(): AstVariableAssignment {
			$nameToken = $this->lexer->match(Token::Identifier);
			$name = $nameToken->getStringValue();
			
			if ($this->lexer->peekIncrementOperator()) {
				if ($this->lexer->peek()->getOffset() !== $nameToken->getOffset() + strlen($name)) {
					throw new ParserException("Write '{$this->incrementOperator()}' directly after '{$name}' on line {$this->lexer->getLineNumber()}");
				}
				
				return $this->compound($name, $this->matchIncrementOperator(), new AstNumber('1'));
			}
			
			foreach (self::COMPOUND_OPERATORS as $token => $operator) {
				if ($this->lexer->peekAdjacent($token, Token::Equals)) {
					$this->lexer->match($token);
					$this->lexer->match(Token::Equals);
					return $this->compound($name, $operator, $this->expressionRule->parse());
				}
			}
			
			throw new ParserException("Expected '++', '--', '+=', '-=', '*=' or '/=' after '{$name}' on line {$this->lexer->getLineNumber()}");
		}
		
		/**
		 * The upcoming `++` or `--`, as text.
		 * @return string
		 */
		private function incrementOperator(): string {
			return $this->lexer->lookahead() === Token::Plus ? '++' : '--';
		}
		
		/**
		 * Consumes the upcoming `++` or `--`.
		 * @return string '+' or '-'
		 * @throws LexerException
		 */
		private function matchIncrementOperator(): string {
			$sign = $this->lexer->lookahead();
			$this->lexer->match($sign);
			$this->lexer->match($sign);
			return $sign === Token::Plus ? '+' : '-';
		}
		
		/**
		 * Builds `name = name <operator> value`.
		 * @param string $name Variable assigned to
		 * @param string $operator '+', '-', '*' or '/'
		 * @param AstInterface $value Right-hand operand
		 * @return AstVariableAssignment
		 */
		private function compound(string $name, string $operator, AstInterface $value): AstVariableAssignment {
			$current = new AstIdentifier($name);
			
			$expression = in_array($operator, ['*', '/'], true)
				? new AstFactor($current, $value, $operator)
				: new AstTerm($current, $value, $operator);
			
			return new AstVariableAssignment($name, $expression);
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
		 * `replace <range> (...) where ...`; the target must be a declared range
		 * (see Rules\Replace/TargetRangeResolver) — a cursor name is rejected
		 * there as an undefined range, same as any other undeclared name.
		 * @return AstInterface AstReplace
		 * @throws LexerException|ParserException
		 */
		private function parseReplace(): AstInterface {
			return (new Replace($this->lexer))->parse($this->ranges);
		}

		/**
		 * `delete <range> where ...`; the target must be a declared range
		 * (see Rules\Delete/TargetRangeResolver).
		 * @return AstInterface AstDelete
		 * @throws LexerException|ParserException
		 */
		private function parseDelete(): AstInterface {
			return (new Delete($this->lexer))->parse([], $this->ranges);
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
