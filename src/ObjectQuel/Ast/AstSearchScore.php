<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSearchScore
	 *
	 * Represents a search_score() function call in the ObjectQuel AST.
	 * This node is used in SELECT and ORDER BY clauses to retrieve the relevance
	 * score of a full-text search as a numeric value.
	 *
	 * search_score() requires a full-text index to be defined on the searched columns.
	 * If no matching FullTextIndex annotation is found, an exception is thrown at
	 * SQL generation time rather than silently falling back to an approximation.
	 *
	 * Usage:
	 *   retrieve (p, search_score(p.name, p.description, :term) as score)
	 *   where search(p.name, p.description, :term)
	 *   sort by score desc
	 *
	 * Emits: MATCH(`range`.`col1`, `range`.`col2`) AGAINST(:term IN BOOLEAN MODE)
	 */
	class AstSearchScore extends Ast {
		
		/**
		 * @var AstIdentifier[] The fields to include in the MATCH() expression
		 */
		protected array $identifiers;
		
		/**
		 * @var AstString|AstParameter The search term
		 */
		protected AstString|AstParameter $searchString;
		
		/**
		 * AstSearchScore constructor.
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString
		 */
		public function __construct(array $identifiers, AstString|AstParameter $searchString) {
			$this->identifiers = $identifiers;
			$this->searchString = $searchString;
			
			// Set parent relationships so the tree is fully linked
			$this->searchString->setParent($this);
			
			foreach ($this->identifiers as $identifier) {
				$identifier->setParent($this);
			}
		}
		
		/**
		 * Accept the visitor and forward to all child identifier nodes.
		 * The search string is not forwarded — it is read directly during SQL generation.
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
		 * Get the field identifiers to search across
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
		
		/**
		 * Get the search string or parameter node
		 * @return AstString|AstParameter
		 */
		public function getSearchString(): AstString|AstParameter {
			return $this->searchString;
		}
		
		/**
		 * Deep clone this node and all its children
		 * @return static
		 */
		public function deepClone(): static {
			$clonedIdentifiers = $this->cloneArray($this->identifiers);
			$clonedSearchString = $this->searchString->deepClone();
			
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifiers, $clonedSearchString);
		}
	}