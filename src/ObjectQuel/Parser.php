<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Append;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\CreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\CreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Delete;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Destroy;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Range;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Replace;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Retrieve;

    class Parser {

        protected Lexer $lexer;
        private Range $rangeRule;
		private Retrieve $retrieveRule;
		private CreateTable $createTableRule;
		private CreateIndex $createIndexRule;
		private Destroy $destroyRule;
		private Append $appendRule;
		private Replace $replaceRule;
		private Delete $deleteRule;

		/**
         * Parser constructor.
         * @param Lexer $lexer
         */
        public function __construct(Lexer $lexer) {
            $this->lexer = $lexer;
            $this->rangeRule = new Range($lexer);
            $this->retrieveRule = new Retrieve($lexer);
            $this->createTableRule = new CreateTable($lexer);
            $this->createIndexRule = new CreateIndex($lexer);
            $this->destroyRule = new Destroy($lexer);
            $this->appendRule = new Append($lexer);
            $this->replaceRule = new Replace($lexer);
            $this->deleteRule = new Delete($lexer);
        }
		
	    /**
	     * Parse queries
	     * @return AstInterface|null
	     * @throws LexerException|ParserException|\ReflectionException
	     */
	    public function parse(): ?AstInterface {
		    // Compiler directives
		    $directives = $this->parseCompilerDirectives();
		    
		    // Ranges
		    $ranges = $this->parseRanges();
		    
		    // Continue parsing until a break condition is reached.
		    $queries = [];
		    
		    do {
		    // Get the next token without changing the position in the lexer.
			    $token = $this->lexer->peek();
			    
			    // Check if the token is a 'Retrieve' type.
			    switch($token->getType()) {
				    case Token::Retrieve :
					    $queries[] = $this->retrieveRule->parse($directives, $ranges);
					    break;

				    case Token::Create :
					    // Ranges ahead of `create` (if any) are simply unused —
					    // still available to any `retrieve` elsewhere in this loop.
					    $queries[] = $this->createTableRule->parse();
					    break;

				    case Token::Destroy :
					    $queries[] = $this->destroyRule->parse();
					    break;

				    case Token::Index :
					    // Ranges ahead of `index` (if any) are simply unused,
					    // same as `create` above.
					    $queries[] = $this->createIndexRule->parse();
					    break;

				    case Token::Append :
					    $queries[] = $this->appendRule->parse($ranges);
					    break;

				    case Token::Replace :
					    $queries[] = $this->replaceRule->parse($ranges);
					    break;

				    case Token::Delete :
					    // No lookahead/dispatch needed — unlike a `delete table Name`
					    // spelling some designs assume, authentic QUEL's drop verb is
					    // `destroy`, a completely separate keyword and token, so
					    // Token::Delete always means this DML verb.
					    $queries[] = $this->deleteRule->parse($ranges);
					    break;

				    default :
					    $tokenName = Token::toString($token->getType()) ?: 'unknown';
					    throw new ParserException("Unexpected token '{$tokenName}' on line {$this->lexer->getLineNumber()}");
			    }
		    } while ($this->lexer->peek()->getType() !== Token::Eof);
		    
		    // Return the first query AST object from the array.
		    // Note: This assumes there is only one query.
		    return $queries[0];
	    }
	    
	    /**
	     * Helper function to match and return the value of a directive.
	     * @param string $directiveName
	     * @return bool|int|float|string
	     * @throws ParserException
	     * @throws LexerException
	     */
	    protected function matchDirectiveValue(string $directiveName): bool|int|float|string {
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
			    
			    // Gebruik van een helper functie om de toewijzing te vereenvoudigen
			    $directives[$directiveName] = $this->matchDirectiveValue($directiveName);
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