<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
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

    class Parser {

		/** Words that start a top-level statement by text; `define` is dispatched by QueryExecutor. A routine by such a name couldn't be called as a statement. */
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
        }
		
	    /**
	     * Parse queries
	     * @return AstInterface
	     * @throws LexerException|ParserException|\ReflectionException
	     */
	    public function parse(): AstInterface {
		    // Compiler directives
		    $directives = CompilerDirectiveParser::parse($this->lexer);
		    
		    // Ranges
		    $ranges = $this->parseRanges();
		    
		    // Parse exactly one statement; QueryExecutor executes one AST at a time.
		    // Get the next token without changing the position in the lexer.
			    $token = $this->lexer->peek();

			    // create/destroy/hide/show/index/replace/delete have no token
			    // type (see Lexer::peekKeyword()) so — unlike Retrieve/Append —
			    // they're recognized by text. Any other `name(` is a routine call.
			    if ($token->getType() === Token::Retrieve) {
				    $query = $this->retrieveRule->parse($directives, $ranges);
			    } elseif ($token->getType() === Token::Append) {
				    $query = $this->appendRule->parse($ranges);
			    } elseif ($this->lexer->peekKeyword('create')) {
				    // Ranges ahead of `create` (if any) are unused.
				    $query = $this->createTableRule->parse();
			    } elseif ($this->lexer->peekKeyword('alter')) {
				    // Ranges ahead of `alter` (if any) are unused.
				    $query = $this->alterTableRule->parse();
			    } elseif ($this->lexer->peekKeyword('destroy')) {
				    $query = $this->destroyRule->parse();
			    } elseif ($this->lexer->peekKeyword('hide')) {
				    $query = $this->indexVisibilityRule->parseHide();
			    } elseif ($this->lexer->peekKeyword('show')) {
				    $query = $this->indexVisibilityRule->parseShow();
			    } elseif ($this->lexer->peekKeyword('index')) {
				    // Ranges ahead of `index` (if any) are unused.
				    $query = $this->indexRule->parse();
			    } elseif ($this->lexer->peekKeyword('replace')) {
				    $query = $this->replaceRule->parse($ranges);
			    } elseif ($this->lexer->peekKeyword('delete')) {
				    // No lookahead needed — QUEL's drop verb is `destroy`, a
				    // separate keyword; the literal word `delete` always
				    // means this DML verb.
				    $query = $this->deleteRule->parse($directives, $ranges);
			    } elseif ($token->getType() === Token::Identifier && $this->lexer->peekNext() === Token::ParenthesesOpen) {
				    $query = $this->callRule->parse();
			    } else {
				    $tokenName = Token::toString($token->getType()) ?: 'unknown';
				    throw new ParserException("Unexpected token '{$tokenName}' on line {$this->lexer->getLineNumber()}");
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
