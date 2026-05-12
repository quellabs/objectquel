<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Represents a search() condition that will be rendered as LIKE / NOT LIKE chains.
	 *
	 * Produced by SearchStrategyResolver when no FullTextIndex covers the searched columns.
	 * Carries the column identifiers, the original search string, a stable search key for
	 * parameter naming, and — when the search string is a literal — the pre-parsed term
	 * buckets (or_terms, and_terms, not_terms).
	 *
	 * When the search string is an AstParameter the value is not known until execution,
	 * so $parsed is null and the executor calls parseSearchData() itself once the runtime
	 * parameter value is available.
	 */
	class AstSearchLike extends Ast implements NodeSearch {
		
		/** @var AstIdentifier[] */
		private array $identifiers;
		
		private AstString|AstParameter $searchString;
		
		/**
		 * Pre-parsed term buckets, set at planning time when the search string is
		 * a literal. Null when the search string is a parameter — in that case the
		 * executor must call parseSearchData() at render time.
		 *
		 * @var array{or_terms: string[], and_terms: string[], not_terms: string[]}|null
		 */
		private ?array $parsed;
		
		/**
		 * Unique key used to generate distinct parameter names for bound LIKE values.
		 * Generated at planning time so parameter names are stable across renders.
		 * @var string
		 */
		private string $searchKey;
		
		/**
		 * @param AstIdentifier[] $identifiers Column identifiers to search across
		 * @param AstString|AstParameter $searchString The original search string node
		 * @param array{or_terms: string[], and_terms: string[], not_terms: string[]}|null $parsed
		 *        Pre-parsed term buckets, or null when the search string is a runtime parameter
		 * @param string $searchKey Unique key for generating distinct parameter names
		 */
		public function __construct(
			array              $identifiers,
			AstString|AstParameter $searchString,
			?array             $parsed,
			string             $searchKey
		) {
			$this->identifiers  = $identifiers;
			$this->searchString = $searchString;
			$this->parsed       = $parsed;
			$this->searchKey    = $searchKey;
			
			// Wire parent references so the AST can be traversed upward
			$this->searchString->setParent($this);
			
			foreach ($this->identifiers as $identifier) {
				$identifier->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor.
		 *
		 * Visits this node first, then each identifier in order. The search string
		 * is not visited here because BuildSqlFromAst marks it via getAstChildren()
		 * when it marks this node's subtree as visited.
		 *
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
		 * Returns the column identifiers that will be searched with LIKE conditions.
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
		
		/**
		 * Returns the original search string node.
		 * @return AstString|AstParameter
		 */
		public function getSearchString(): AstString|AstParameter {
			return $this->searchString;
		}
		
		/**
		 * Returns the pre-parsed term buckets, or null when the search string is a
		 * runtime parameter and parsing must be deferred to render time.
		 * @return array{or_terms: string[], and_terms: string[], not_terms: string[]}|null
		 */
		public function getParsed(): ?array {
			return $this->parsed;
		}
		
		/**
		 * Returns the unique key used to generate distinct bound parameter names for
		 * the LIKE values produced from this search node.
		 * @return string
		 */
		public function getSearchKey(): string {
			return $this->searchKey;
		}
		
		/**
		 * Parse the search string into or/and/not term buckets.
		 *
		 * Called by the executor when $parsed is null, which happens when the search
		 * string was an AstParameter at planning time and its value was not yet known.
		 * Quoted phrases are treated as OR terms, +prefixed words are required (AND),
		 * and -prefixed words are excluded (NOT).
		 *
		 * @param array<string, mixed> $parameters Runtime query parameters
		 * @return array{or_terms: string[], and_terms: string[], not_terms: string[]}
		 * @throws QuelException
		 */
		public function parseSearchData(array $parameters): array {
			// Resolve the search string value — either a literal or a bound parameter
			if ($this->searchString instanceof AstString) {
				$searchString = $this->searchString->getValue();
			} else {
				$searchString = $parameters[$this->searchString->getName()] ?? '';
			}
			
			// Split on whitespace while respecting quoted phrases
			$pattern = '/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/';
			$tokens  = preg_split($pattern, $searchString);
			
			if ($tokens === false) {
				$errorCode = preg_last_error();
				$errorMessage = function_exists('preg_last_error_msg')
					? preg_last_error_msg()
					: "PCRE error code {$errorCode}";
				
				throw new QuelException(sprintf(
					'Failed to parse search string "%s": %s',
					$searchString,
					$errorMessage
				));
			}
			
			// Parse the items
			$parsed = ['or_terms' => [], 'and_terms' => [], 'not_terms' => []];
			
			foreach ($tokens as $token) {
				if (preg_match('/^"(.+)"$/', $token, $matches)) {
					// Quoted phrase — treat as an OR term (exact match required)
					$parsed['or_terms'][] = $matches[1];
				} elseif (str_starts_with($token, '+')) {
					// +word — must match (AND)
					$parsed['and_terms'][] = trim(substr($token, 1), '"');
				} elseif (str_starts_with($token, '-')) {
					// -word — must not match (NOT)
					$parsed['not_terms'][] = trim(substr($token, 1), '"');
				} else {
					// Plain word — any match is sufficient (OR)
					$parsed['or_terms'][] = $token;
				}
			}
			
			return $parsed;
		}
		
		/**
		 * Returns a deep clone of this node with independent copies of all children.
		 * The parsed buckets and search key are value types and are copied as-is.
		 * @return static
		 */
		public function deepClone(): static {
			$clonedIdentifiers  = $this->cloneArray($this->identifiers);
			$clonedSearchString = $this->searchString->deepClone();
			
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifiers, $clonedSearchString, $this->parsed, $this->searchKey);
		}
	}