<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSearch
	 */
	class AstSearch extends Ast {
		
		/**
		 * @var AstIdentifier[]
		 */
		protected array $identifiers;
		protected AstString|AstParameter $searchString;
		
		/**
		 * AstSearch constructor.
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString
		 */
		public function __construct(array $identifiers, AstString|AstParameter $searchString) {
			$this->identifiers = $identifiers;
			$this->searchString = $searchString;
		}
		
		/**
		 * Parse a single token from the search string.
		 * @param string $token The token to parse
		 * @return array An array containing the token type and value
		 */
		private function parseToken(string $token): array {
			if (preg_match('/^"(.+)"$/', $token, $matches)) {
				return ['type' => 'exact', 'value' => $matches[1]];
			} elseif (str_starts_with($token, '+')) {
				return ['type' => 'required', 'value' => trim(substr($token, 1), '"')];
			} elseif (str_starts_with($token, '-')) {
				return ['type' => 'excluded', 'value' => trim(substr($token, 1), '"')];
			} else {
				return ['type' => 'regular', 'value' => $token];
			}
		}
		
		/**
		 * Accept the node
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			foreach($this->identifiers as $identifier) {
				$identifier->accept($visitor);
			}
		}
		
		/**
		 * Get the identifiers to use
		 * @return AstIdentifier[] The identifiers used for search activities
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
		
		/**
		 * Sets the identifiers to use
		 * @param AstIdentifier[] $identifiers
		 * @return void
		 */
		public function setIdentifiers(array $identifiers): void {
			$this->identifiers = $identifiers;
		}
		
		/**
		 * Extract search data from the search string.
		 * @return array An array containing parsed terms and operators
		 */
		public function parseSearchData(array $parameters): array {
			// Fetch search string contents
			if ($this->searchString instanceof AstString) {
				$searchString = $this->searchString->getValue();
			} else {
				$searchString = $parameters[$this->searchString->getName()] ?? '';
			}
			
			// Split the search string into tokens, preserving quoted phrases
			$tokens = preg_split('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/', $searchString);
			
			// Put the result of the parsing here
			$parsed = [
				'or_terms'  => [],
				'and_terms' => [],
				'not_terms' => []
			];
			
			foreach ($tokens as $token) {
				$parsedToken = $this->parseToken($token);
				
				switch ($parsedToken['type']) {
					case 'required':
						$parsed['and_terms'][] = $parsedToken['value'];
						break;
					
					case 'excluded':
						$parsed['not_terms'][] = $parsedToken['value'];
						break;
					
					default:
						$parsed['or_terms'][] = $parsedToken['value'];
						break;
				}
			}
			
			return $parsed;
		}
	}