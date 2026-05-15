<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor class that detects if an AST node contains references to JSON sources
	 * This visitor is used to identify mixed data source references in query conditions
	 */
	class CollectJoinIdentifiers implements AstVisitorInterface {

		/** @var AstRetrieve Retrieve AST Node */
		private AstRetrieve $retrieve;

		/** @var array<int, AstIdentifier> */
		private array $identifiers = [];
		
		/**
		 * CollectJoinConditionIdentifiers Constructor
		 * @param AstRetrieve $retrieve
		 */
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
		}
		
		/**
		 * Returns a list of range name used in the query
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// We only care about identifier nodes, since these reference fields from ranges
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			/**
			 * Only use root nodes
			 */
			if ($node->hasParentIdentifier()) {
				return;
			}
			
			/**
			 * Only use database ranges and JSON source ranges.
			 * JSON source ranges appear in cross-source join conditions (e.g. y.id=x.id)
			 * and their referenced columns must also be injected as hidden projections
			 * so the in-memory join can evaluate the condition against the result row.
			 */
			$range = $node->getRange();
			
			if (
				!$range instanceof AstRangeDatabase &&
				!$range instanceof AstRangeJsonSource
			) {
				return;
			}
			
			/*
			 * Range is present in query
			 */
			if (!$this->retrieve->hasRange($range)) {
				return;
			}
			
			/**
			 * Do not use if we optimized the range away using a subquery.
			 * This flag only exists on AstRangeDatabase; JSON source ranges are
			 * never promoted to subqueries, so the check is skipped for them.
			 */
			if (!$range->includeAsJoin()) {
				return;
			}
			
			// If we reached here, we found a reference
			$this->identifiers[] = $node;
		}
		
		/**
		 * Returns the gathered identifiers
		 * @return AstIdentifier[]
		 */
		public function getIdentifiers(): array {
			return $this->identifiers;
		}
	}