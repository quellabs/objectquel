<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Abstract base for all search() AST nodes.
	 *
	 * Holds the column identifiers and search string shared by every search
	 * strategy, and provides parseSearchData() so callers can obtain or/and/not
	 * term buckets without knowing the concrete strategy type.
	 *
	 * Implemented by AstSearch, AstSearchLike, AstSearchFullText, AstSearchScore.
	 */
	abstract class NodeSearch extends Ast {
		
		/** @var AstIdentifier[] */
		protected array $identifiers;
		
		protected AstString|AstParameter $searchString;
		
		/**
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString
		 */
		public function __construct(array $identifiers, AstString|AstParameter $searchString) {
			$this->identifiers = $identifiers;
			$this->searchString = $searchString;
			
			$this->searchString->setParent($this);
			
			foreach ($this->identifiers as $identifier) {
				$identifier->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor, then recurse into each identifier.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			foreach ($this->identifiers as $identifier) {
				$identifier->accept($visitor);
			}
		}
		
		/**
		 * Returns the list of column identifiers this search operates over.
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
		
		/**
		 * Replaces the list of column identifiers.
		 * @param AstIdentifier[] $identifiers
		 * @return void
		 */
		public function setIdentifiers(array $identifiers): void {
			$this->identifiers = $identifiers;
		}
		
		/**
		 * Returns the search string or parameter node.
		 * @return AstString|AstParameter
		 */
		public function getSearchString(): AstString|AstParameter {
			return $this->searchString;
		}
		
		/**
		 * Parses the search string into or/and/not term buckets.
		 *
		 * Plain words    → or_terms  (any match is sufficient)
		 * +word          → and_terms (must match at least one field)
		 * -word          → not_terms (must not match any field)
		 * "quoted phrase" → or_terms  (treated as an exact or-term)
		 *
		 * When the search string is an AstParameter the value is resolved
		 * from $parameters at call time.
		 *
		 * @param array<string, mixed> $parameters Runtime query parameters
		 * @return array{or_terms: list<string>, and_terms: list<string>, not_terms: list<string>}
		 * @throws QuelException
		 */
		public function parseSearchData(array $parameters): array {
			// Resolve the search string from either a literal value or a runtime parameter
			if ($this->searchString instanceof AstString) {
				$searchString = $this->searchString->getValue();
			} else {
				$searchString = $parameters[$this->searchString->getName()] ?? '';
				
				if (!is_string($searchString)) {
					throw new QuelException(sprintf(
						'Parameter "%s" must be a string, got %s',
						$this->searchString->getName(),
						get_debug_type($searchString)
					));
				}
			}
			
			// Split on whitespace that is not inside double quotes,
			// so quoted phrases like "foo bar" are kept as single tokens
			$pattern = '/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/';
			$tokens = preg_split($pattern, $searchString);
			
			if ($tokens === false) {
				throw new QuelException(sprintf(
					'Failed to parse search string "%s": %s',
					$searchString,
					function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'PCRE error ' . preg_last_error()
				));
			}
			
			$parsed = ['or_terms' => [], 'and_terms' => [], 'not_terms' => []];
			
			foreach ($tokens as $token) {
				if (preg_match('/^"(.+)"$/', $token, $matches)) {
					// Quoted phrase → or_term (matched as a single exact unit)
					$parsed['or_terms'][] = $matches[1];
				} elseif (str_starts_with($token, '+')) {
					// +word → and_term (must match at least one field)
					$parsed['and_terms'][] = trim(substr($token, 1), '"');
				} elseif (str_starts_with($token, '-')) {
					// -word → not_term (must not match any field)
					$parsed['not_terms'][] = trim(substr($token, 1), '"');
				} else {
					// Plain word → or_term (any match is sufficient)
					$parsed['or_terms'][] = $token;
				}
			}
			
			return $parsed;
		}
	}