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
		 * Name of the original :parameter this node's search string consumed, set only
		 * when planning confirmed no other part of the query still references that same
		 * name. Null when the search string was a literal, or when the name is shared
		 * with another usage elsewhere in the query and must stay bound for it.
		 * @var string|null
		 */
		private ?string $exclusiveParameterName;

		/**
		 * @param AstIdentifier[]        $identifiers
		 * @param AstString|AstParameter $searchString
		 * @param array{or_terms: string[], and_terms: string[], not_terms: string[]}|null $parsed
		 * @param string                 $searchKey
		 * @param string|null            $exclusiveParameterName
		 */
		public function __construct(
			array                  $identifiers,
			AstString|AstParameter $searchString,
			?array                 $parsed,
			string                 $searchKey,
			?string                $exclusiveParameterName = null
		) {
			parent::__construct($identifiers, $searchString);
			$this->parsed                 = $parsed;
			$this->searchKey              = $searchKey;
			$this->exclusiveParameterName = $exclusiveParameterName;
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
		 * Returns the name of the original :parameter this node's search string
		 * consumed when it is safe to unbind after rendering, or null when the
		 * search string was a literal or that name is still used elsewhere.
		 * @return string|null
		 */
		public function getExclusiveParameterName(): ?string {
			return $this->exclusiveParameterName;
		}

		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->cloneArray($this->identifiers), $this->searchString->deepClone(), $this->parsed, $this->searchKey, $this->exclusiveParameterName);
		}
	}