<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\AlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Append;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Call;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\CreateIndex;
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
		private CreateIndex $createIndexRule;
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
            $this->createIndexRule = new CreateIndex($lexer);
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
		    $directives = $this->parseCompilerDirectives();
		    
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
				    $query = $this->createIndexRule->parse();
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
	     * Helper function to match and return the value of a directive.
	     * @param string $directiveName
	     * @return bool|int|float|string
	     * @throws ParserException
	     * @throws LexerException
	     */
	    protected function matchDirectiveValue(string $directiveName): bool|int|float|string {
		    if ($this->lexer->optionalMatch(Token::Minus)) {
			    return -$this->lexer->match(Token::Number)->getNumericValue();
		    }

		    if ($this->lexer->optionalMatch(Token::True)) {
			    return true;
		    } elseif ($this->lexer->optionalMatch(Token::False)) {
			    return false;
		    } elseif (($token = $this->lexer->optionalMatch(Token::Number)) !== null) {
			    return $token->getNumericValue();
		    } elseif (($token = $this->lexer->optionalMatch(Token::Identifier)) !== null) {
			    return $token->getStringValue();
		    } else {
			    throw new ParserException("Invalid compiler directive value for @{$directiveName}");
		    }
	    }
	    
	    /**
	     * Parser compiler directives
	     * @return array<string, bool|int|float|string>
	     * @throws LexerException|ParserException
	     */
	    protected function parseCompilerDirectives(): array {
		    $directives = [];
		    
		    while ($this->lexer->peek()->getType() == Token::CompilerDirective) {
			    $directive = $this->lexer->match(Token::CompilerDirective);
			    $directiveName = $directive->getStringValue();

			    // Stored lowercase so directive names are case-insensitive —
			    // see AstRetrieve/AstDelete::getDirective(), which lowercases
			    // the lookup key the same way.
			    $directives[strtolower($directiveName)] = $this->matchDirectiveValue($directiveName);
		    }
		    
		    return $directives;
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
