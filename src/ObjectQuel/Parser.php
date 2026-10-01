<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\RangeListParser;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\AlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Append;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Call;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Index;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\CreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Delete;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Destroy;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\IndexVisibility;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Replace;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Retrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineDefinition;

    class Parser {

		/** Words that start a top-level statement by text. A routine by such a name couldn't be called as a statement. */
		public const array STATEMENT_KEYWORDS = ['create', 'alter', 'destroy', 'hide', 'show', 'index', 'replace', 'delete', 'define', 'retrieve', 'append'];

        protected Lexer $lexer;
        private EntityStore $entityStore;
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
         * @param EntityStore $entityStore Used to resolve `range of x is Name` against declared entities, here and in a routine's own body
         */
        public function __construct(Lexer $lexer, EntityStore $entityStore) {
            $this->lexer = $lexer;
            $this->entityStore = $entityStore;
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
		    $directives = $this->parseDirectives();

		    // Ranges
		    $ranges = RangeListParser::parse($this->lexer, $this->entityStore);

		    // Parse exactly one statement; QueryExecutor executes one AST at a time.
		    // Get the next token without changing the position in the lexer.
		    $token = $this->lexer->peek();

		    // None of these have a dedicated token type (see
		    // Lexer::peekKeyword()); each is recognized by text, so a
		    // column/entity/routine can still be named after one elsewhere.
		    // Any other `name(` is a routine call. Only retrieve/append/
		    // replace/delete use a leading range; every other kind rejects
		    // one instead of silently discarding it.
		    if ($this->lexer->peekKeyword('retrieve')) {
			    $query = $this->retrieveRule->parse($directives, $ranges);
		    } elseif ($this->lexer->peekKeyword('append')) {
			    $query = $this->appendRule->parse($ranges);
		    } elseif ($this->lexer->peekKeyword('create')) {
			    $this->rejectRanges($ranges, 'create');
			    $query = $this->createTableRule->parse();
		    } elseif ($this->lexer->peekKeyword('alter')) {
			    $this->rejectRanges($ranges, 'alter');
			    $query = $this->alterTableRule->parse();
		    } elseif ($this->lexer->peekKeyword('destroy')) {
			    $this->rejectRanges($ranges, 'destroy');
			    $query = $this->destroyRule->parse();
		    } elseif ($this->lexer->peekKeyword('hide')) {
			    $this->rejectRanges($ranges, 'hide');
			    $query = $this->indexVisibilityRule->parseHide();
		    } elseif ($this->lexer->peekKeyword('show')) {
			    $this->rejectRanges($ranges, 'show');
			    $query = $this->indexVisibilityRule->parseShow();
		    } elseif ($this->lexer->peekKeyword('index')) {
			    $this->rejectRanges($ranges, 'index');
			    $query = $this->indexRule->parse();
		    } elseif ($this->lexer->peekKeyword('define')) {
			    $this->rejectRanges($ranges, 'define');
			    $query = $this->routineDefinitionRule->parse($directives);
		    } elseif ($this->lexer->peekKeyword('replace')) {
			    $query = $this->replaceRule->parse($ranges);
		    } elseif ($this->lexer->peekKeyword('delete')) {
			    // No lookahead needed — QUEL's drop verb is `destroy`, a
			    // separate keyword; the literal word `delete` always
			    // means this DML verb.
			    $query = $this->deleteRule->parse($directives, $ranges);
		    } elseif ($token->getType() === Token::Identifier && $this->lexer->peekNext() === Token::ParenthesesOpen) {
			    $this->rejectRanges($ranges, 'a routine call');
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
	     * Rejects a leading range ahead of a statement kind that doesn't use one.
	     * @param AstRange[] $ranges
	     * @param string $statement Name used in the error message
	     * @throws ParserException
	     */
	    private function rejectRanges(array $ranges, string $statement): void {
		    if ($ranges !== []) {
			    throw new ParserException("A leading range declaration isn't allowed before '{$statement}'.");
		    }
	    }

	    /**
	     * Parses zero or more `@name value` compiler directives, e.g. `@ignoreSoftDelete true`.
	     * @return array<string, bool|int|float|string> Directive values by lowercased name
	     * @throws LexerException|ParserException
	     */
	    private function parseDirectives(): array {
		    $directives = [];

		    while ($this->lexer->peek()->getType() == Token::CompilerDirective) {
			    $directive = $this->lexer->match(Token::CompilerDirective);
			    $directiveName = $directive->getStringValue();

			    // Stored lowercase so directive names are case-insensitive —
			    // see AstRetrieve/AstDelete/AstRoutineDefinition::getDirective(),
			    // which lowercase the lookup key the same way.
			    $directives[strtolower($directiveName)] = $this->matchDirectiveValue($directiveName);
		    }

		    return $directives;
	    }

	    /**
	     * @param string $directiveName Used only for the error message
	     * @return bool|int|float|string
	     * @throws LexerException|ParserException
	     */
	    private function matchDirectiveValue(string $directiveName): bool|int|float|string {
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
    }
