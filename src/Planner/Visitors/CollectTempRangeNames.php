<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that collects the names of all temp ranges referenced in an AST subtree.
	 * Only range names present in the provided closed set are collected.
	 * Safe to reuse across multiple accept() calls — results accumulate.
	 */
	class CollectTempRangeNames implements AstVisitorInterface {
		
		/** @var string[] The closed set of temp range names to match against */
		private array $tempRangeNames;
		
		/** @var string[] Collected range names, may contain duplicates across calls */
		private array $collected = [];
		
		/**
		 * @param string[] $tempRangeNames Only ranges whose names appear here will be collected
		 */
		public function __construct(array $tempRangeNames) {
			$this->tempRangeNames = $tempRangeNames;
		}
		
		/**
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Only identifiers carry range references; all other node types are irrelevant
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			$range = $node->getRange();
			
			// Strict comparison avoids false positives from numeric-looking range names
			// that PHP would otherwise coerce during a loose in_array() check
			if ($range !== null && in_array($range->getName(), $this->tempRangeNames, strict: true)) {
				// Duplicates are allowed here — deduplication is deferred to getCollected()
				// to avoid repeated array_unique() work during traversal
				$this->collected[] = $range->getName();
			}
		}
		
		/**
		 * Returns deduplicated list of collected temp range names.
		 * Deduplication is deferred to here rather than during traversal to avoid
		 * repeated work across multiple accept() calls.
		 * @return string[]
		 */
		public function getCollected(): array {
			return array_unique($this->collected);
		}
	}