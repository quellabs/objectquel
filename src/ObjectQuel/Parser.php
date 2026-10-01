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
		    $query = $this->dispatchStatement($directives, $ranges);

		    if ($this->lexer->lookahead() !== Token::Eof) {
			    throw new ParserException('Unexpected content after the statement; only one statement is allowed per query.');
		    }

		    return $query;
	    }

	    /**
	     * Dispatches on the current token to the rule for the statement it starts.
	     * @param array<string, mixed> $directives Compiler directives parsed ahead of the statement
	     * @param AstRange[] $ranges Ranges parsed ahead of the statement
	     * @return AstStatement
	     * @throws LexerException|ParserException|\ReflectionException
	     */
	    private function dispatchStatement(array $directives, array $ranges): AstStatement {
		    // Get the next token without changing the position in the lexer.
		    $token = $this->lexer->peek();

		    // None of these have a dedicated token type (see
		    // Lexer::peekKeyword()); each is recognized by text, so a
		    // column/entity/routine can still be named after one elsewhere.
		    // Any other `name(` is a routine call. Only retrieve/append/
		    // replace/delete use a leading range; every other kind rejects
		    // one instead of silently discarding it.
		    $keyword = $token->getType() === Token::Identifier ? strtolower($token->getStringValue()) : null;

		    switch ($keyword) {
			    case 'retrieve':
				    return $this->retrieveRule->parse($directives, $ranges);

			    case 'append':
				    return $this->appendRule->parse($ranges);

			    case 'create':
				    $this->rejectRanges($ranges, 'create');
				    return $this->createTableRule->parse();

			    case 'alter':
				    $this->rejectRanges($ranges, 'alter');
				    return $this->alterTableRule->parse();

			    case 'destroy':
				    $this->rejectRanges($ranges, 'destroy');
				    return $this->destroyRule->parse();

			    case 'hide':
				    $this->rejectRanges($ranges, 'hide');
				    return $this->indexVisibilityRule->parseHide();

			    case 'show':
				    $this->rejectRanges($ranges, 'show');
				    return $this->indexVisibilityRule->parseShow();

			    case 'index':
				    $this->rejectRanges($ranges, 'index');
				    return $this->indexRule->parse();

			    case 'define':
				    $this->rejectRanges($ranges, 'define');
				    return $this->routineDefinitionRule->parse($directives);

			    case 'replace':
				    return $this->replaceRule->parse($ranges);

			    case 'delete':
				    // No lookahead needed — QUEL's drop verb is `destroy`, a
				    // separate keyword; the literal word `delete` always
				    // means this DML verb.
				    return $this->deleteRule->parse($directives, $ranges);

			    default:
				    if ($token->getType() === Token::Identifier && $this->lexer->peekNext() === Token::ParenthesesOpen) {
					    $this->rejectRanges($ranges, 'a routine call');
					    return $this->callRule->parse();
				    }

				    $tokenName = Token::toString($token->getType()) ?: 'unknown';
				    throw new ParserException("Unexpected token '{$tokenName}' on line {$this->lexer->getLineNumber()}");
		    }
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
