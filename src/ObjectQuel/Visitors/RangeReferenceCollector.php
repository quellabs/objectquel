<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Collects the names of all declared ranges referenced by EntityRoot or
	 * EntityReference identifier nodes within an expression tree.
	 *
	 * Used by SemanticAnalyzer::validateNoCircularViaDependencies() to extract
	 * which ranges a join expression depends on. The expression may be arbitrarily
	 * complex (binary operators, function calls, identifier chains) — the visitor
	 * pattern handles traversal correctly for all node types.
	 */
	class RangeReferenceCollector implements AstVisitorInterface {
		
		/**
		 * Map of declared range names used for fast membership lookup.
		 * @var array<string, bool>
		 */
		private array $knownRangeNames;
		
		/**
		 * Range names found during traversal.
		 * @var string[]
		 */
		private array $referenced = [];
		
		/**
		 * RangeReferenceCollector constructor
		 * @param array<string, bool> $knownRangeNames Map of declared range names to match against
		 */
		public function __construct(array $knownRangeNames) {
			$this->knownRangeNames = $knownRangeNames;
		}
		
		/**
		 * Visit a node and record its name if it is an EntityRoot or EntityReference
		 * identifier that matches a declared range.
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			$type = $node->getType();
			
			if (
				($type === IdentifierType::EntityRoot || $type === IdentifierType::EntityReference) &&
				isset($this->knownRangeNames[$node->getName()])
			) {
				$this->referenced[] = $node->getName();
			}
		}
		
		/**
		 * Returns all range names collected during traversal.
		 * @return string[]
		 */
		public function getReferencedRanges(): array {
			return $this->referenced;
		}
	}