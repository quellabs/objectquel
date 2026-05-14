<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	/**
	 * Represents a search() condition rendered as a LIKE / NOT LIKE chain.
	 *
	 * Produced by SearchStrategyResolver when no FullTextIndex covers the searched
	 * columns. Carries a stable search key for parameter naming and — when the
	 * search string is a literal — the pre-parsed term buckets so the SQL builder
	 * does not need to re-parse at render time.
	 *
	 * When the search string is an AstParameter the value is not known until
	 * execution, so $parsed is null and the executor calls parseSearchData() once
	 * the runtime parameter value is available.
	 */
	class AstSearchLike extends NodeSearch {
		
		/**
		 * Pre-parsed term buckets, set at planning time when the search string is
		 * a literal. Null when the search string is a parameter.
		 *
		 * @var array{or_terms: string[], and_terms: string[], not_terms: string[]}|null
		 */
		private ?array $parsed;
		
		/**
		 * Unique key used to generate distinct parameter names for bound LIKE values.
		 * @var string
		 */
		private string $searchKey;
		
		/**
		 * @param AstIdentifier[]        $identifiers
		 * @param AstString|AstParameter $searchString
		 * @param array{or_terms: string[], and_terms: string[], not_terms: string[]}|null $parsed
		 * @param string                 $searchKey
		 */
		public function __construct(
			array                  $identifiers,
			AstString|AstParameter $searchString,
			?array                 $parsed,
			string                 $searchKey
		) {
			parent::__construct($identifiers, $searchString);
			$this->parsed    = $parsed;
			$this->searchKey = $searchKey;
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
		 * Returns the unique key used to generate distinct bound parameter names
		 * for the LIKE values produced from this search node.
		 * @return string
		 */
		public function getSearchKey(): string {
			return $this->searchKey;
		}
		
		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone(), $this->parsed, $this->searchKey);
		}
	}