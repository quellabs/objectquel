<?php
    
    namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\ObjectQuel\Rules\Range;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Retrieve;
    
    class Parser {
        
        protected Lexer $lexer;
        private Range $rangeRule;
		private Retrieve $retrieveRule;
		
		/**
         * Parser constructor.
         * @param Lexer $lexer
         */
        public function __construct(Lexer $lexer) {
            $this->lexer = $lexer;
            $this->rangeRule = new Range($lexer);
            $this->retrieveRule = new Retrieve($lexer);
        }
	    
	    /**
	     * Helper function to match and return the value of a directive.
	     * @param string $directiveName
	     * @return mixed
	     * @throws ParserException
	     * @throws LexerException
	     */
	    protected function matchDirectiveValue(string $directiveName): mixed {
		    if ($this->lexer->optionalMatch(Token::True)) {
			    return true;
		    } elseif ($this->lexer->optionalMatch(Token::False)) {
			    return false;
		    } elseif ($this->lexer->optionalMatch(Token::Number, $result)) {
			    return $result->getValue();
		    } elseif ($this->lexer->optionalMatch(Token::Identifier, $result)) {
			    return $result->getValue();
		    } else {
			    throw new ParserException("Invalid compiler directive value for @{$directiveName}");
		    }
	    }
		
		/**
		 * Parser compiler directives
		 * @return array
		 * @throws LexerException|ParserException
		 */
		protected function parseCompilerDirectives(): array {
			$directives = [];
			
			while ($this->lexer->peek()->getType() == Token::CompilerDirective) {
				$directive = $this->lexer->match(Token::CompilerDirective);
				$directiveName = $directive->getValue();
				
				// Gebruik van een helper functie om de toewijzing te vereenvoudigen
				$directives[$directiveName] = $this->matchDirectiveValue($directiveName);
			}
			
			return $directives;
		}
		
		/**
		 * Parse ranges
		 * @return array
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
	    
	    /**
	     * Parse queries
	     * @return AstInterface|null
	     * @throws LexerException|ParserException
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
				    
				    default :
					    throw new ParserException("Unexpected token '{$token->getValue()}' on line {$this->lexer->getLineNumber()}");
			    }
		    } while ($this->lexer->peek()->getType() !== Token::Eof);
		    
		    // Return the first query AST object from the array.
		    // Note: This assumes there is only one query.
		    return $queries[0];
	    }
    }