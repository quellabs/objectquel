<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents a search() condition that will be rendered as a MATCH...AGAINST expression.
	 *
	 * Produced by SearchStrategyResolver when a FullTextIndex covers all searched columns
	 * on the same entity. The raw search string is passed directly to AGAINST in boolean
	 * mode so MySQL's parser handles +/- prefixes natively, avoiding a redundant parse of
	 * the terms inside ObjectQuel.
	 */
	class AstSearchFullText extends Ast implements NodeSearch {
		
		/** @var AstIdentifier[] */
		private array $identifiers;
		
		/** @var AstString|AstParameter The string to search */
		private AstString|AstParameter $searchString;
		
		/**
		 * AstSearchFullText constructor
		 * @param AstIdentifier[] $identifiers Column identifiers to include in the MATCH() list
		 * @param AstString|AstParameter $searchString The raw search string passed to AGAINST
		 */
		public function __construct(array $identifiers, AstString|AstParameter $searchString) {
			$this->identifiers = $identifiers;
			$this->searchString = $searchString;
			
			// Wire parent references so the AST can be traversed upward
			$this->searchString->setParent($this);
			
			foreach ($this->identifiers as $identifier) {
				$identifier->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor.
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
		 * Returns the column identifiers that form the MATCH() column list.
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
		
		/**
		 * Sets new identifiers
		 * @param AstIdentifier[] $identifiers
		 * @return void
		 */
		public function setIdentifiers(array $identifiers): void {
			$this->identifiers = $identifiers;
		}
		
		/**
		 * Returns the search string node passed to AGAINST.
		 * @return AstString|AstParameter
		 */
		public function getSearchString(): AstString|AstParameter {
			return $this->searchString;
		}
		
		/**
		 * Returns a deep clone of this node with independent copies of all children.
		 * @return static
		 */
		public function deepClone(): static {
			$clonedIdentifiers = $this->cloneArray($this->identifiers);
			$clonedSearchString = $this->searchString->deepClone();
			
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifiers, $clonedSearchString);
		}
	}