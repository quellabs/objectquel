<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\CompilerDirectiveParser;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\AlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Append;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Call;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Index;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\CreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Delete;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Destroy;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\IndexVisibility;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Range;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Replace;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Retrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineDefinition;

    class Parser {

		/** Words that start a top-level statement by text. A routine by such a name couldn't be called as a statement. */
		public const array STATEMENT_KEYWORDS = ['create', 'alter', 'destroy', 'hide', 'show', 'index', 'replace', 'delete', 'define'];

        protected Lexer $lexer;
        private Range $rangeRule;
		private Retrieve $retrieveRule;
		private CreateTable $createTableRule;
		private Index $indexRule;
		private AlterTable $alterTableRule;
		private Destroy $destroyRule;
		private IndexVisibility $indexVisibilityRule;
		private Append $appendRule;
		private Replace $replaceRule;
		private Delete $deleteRule;
		private Call $callRule;
		private RoutineDefinition $routineDefinitionRule;

		/**
         * Parser constructor.
         * @param Lexer $lexer
         * @param EntityStore $entityStore Used by the Range rule to resolve `range of x is Name` against declared entities
         */
        public function __construct(Lexer $lexer, EntityStore $entityStore) {
            $this->lexer = $lexer;
            $this->rangeRule = new Range($lexer, $entityStore);
            $this->retrieveRule = new Retrieve($lexer);
            $this->createTableRule = new CreateTable($lexer);
            $this->indexRule = new Index($lexer);
            $this->alterTableRule = new AlterTable($lexer);
            $this->destroyRule = new Destroy($lexer);
            $this->indexVisibilityRule = new IndexVisibility($lexer);
            $this->appendRule = new Append($lexer);
            $this->replaceRule = new Replace($lexer);
            $this->deleteRule = new Delete($lexer);
            $this->callRule = new Call($lexer);
            $this->routineDefinitionRule = new RoutineDefinition($lexer, $entityStore);
        }
		
	    /**
	     * Parse queries
	     * @return AstStatement
	     * @throws LexerException|ParserException|\ReflectionException
	     */
	    public function parse(): AstStatement {
		    // Compiler directives
		    $directives = CompilerDirectiveParser::parse($this->lexer);

		    // Only retrieve/append/replace/delete use leading ranges. Every
		    // other statement kind is recognized by text (see
		    // Lexer::peekKeyword()) and never references a range, so each
		    // is checked here, before range parsing, rejecting e.g.
		    // `range of ...; create ...` instead of silently discarding
		    // the range declaration.
		    if ($this->lexer->peekKeyword('create')) {
			    $query = $this->createTableRule->parse();
		    } elseif ($this->lexer->peekKeyword('alter')) {
			    $query = $this->alterTableRule->parse();
		    } elseif ($this->lexer->peekKeyword('destroy')) {
			    $query = $this->destroyRule->parse();
		    } elseif ($this->lexer->peekKeyword('hide')) {
			    $query = $this->indexVisibilityRule->parseHide();
		    } elseif ($this->lexer->peekKeyword('show')) {
			    $query = $this->indexVisibilityRule->parseShow();
		    } elseif ($this->lexer->peekKeyword('index')) {
			    $query = $this->indexRule->parse();
		    } elseif ($this->lexer->peekKeyword('define')) {
			    $query = $this->routineDefinitionRule->parse($directives);
		    } elseif ($this->lexer->peek()->getType() === Token::Identifier && $this->lexer->peekNext() === Token::ParenthesesOpen) {
			    // Any other `name(` is a routine call.
			    $query = $this->callRule->parse();
		    } else {
			    // Ranges
			    $ranges = $this->parseRanges();

			    // Parse exactly one statement; QueryExecutor executes one AST at a time.
			    // Get the next token without changing the position in the lexer.
				    $token = $this->lexer->peek();

				    if ($token->getType() === Token::Retrieve) {
					    $query = $this->retrieveRule->parse($directives, $ranges);
				    } elseif ($token->getType() === Token::Append) {
					    $query = $this->appendRule->parse($ranges);
				    } elseif ($this->lexer->peekKeyword('replace')) {
					    $query = $this->replaceRule->parse($ranges);
				    } elseif ($this->lexer->peekKeyword('delete')) {
					    // No lookahead needed — QUEL's drop verb is `destroy`, a
					    // separate keyword; the literal word `delete` always
					    // means this DML verb.
					    $query = $this->deleteRule->parse($directives, $ranges);
				    } else {
					    $tokenName = Token::toString($token->getType()) ?: 'unknown';
					    throw new ParserException("Unexpected token '{$tokenName}' on line {$this->lexer->getLineNumber()}");
				    }
		    }

		    if ($this->lexer->lookahead() !== Token::Eof) {
			    throw new ParserException('Unexpected content after the statement; only one statement is allowed per query.');
		    }

		    return $query;
	    }
	    
	    /**
	     * Parse ranges
	     * @return AstRange[]
	     * @throws LexerException
	     * @throws ParserException
	     */
	    protected function parseRanges(): array {
		    $ranges = [];
		    
		    while ($this->lexer->peek()->getType() == Token::Range) {
			    $ranges[] = $this->rangeRule->parse();
		    }
		    
		    return $ranges;
	    }
    }
