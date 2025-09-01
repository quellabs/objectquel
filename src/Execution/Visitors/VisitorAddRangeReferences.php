<?php
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;
	
	use Quellabs\ObjectQuel\Execution\RangeReferences\Reference;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregate;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregateWhere;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceSelect;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceWhere;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhere;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that collects and categorizes range references for query execution planning.
	 *
	 * This visitor implements the visitor pattern to traverse an Abstract Syntax Tree (AST)
	 * and identify all identifiers that reference a specific range. It categorizes these
	 * references based on their context (SELECT, WHERE, aggregates, etc.) to help with
	 * query optimization and execution planning.
	 *
	 * The visitor distinguishes between different contexts:
	 * - SELECT: Identifiers used in selection clauses
	 * - WHERE: Identifiers used in filtering conditions
	 * - AGGREGATE: Identifiers used within aggregate functions
	 * - AGGREGATE_WHERE: Identifiers used in WHERE clauses within aggregate functions
	 */
	class VisitorAddRangeReferences implements AstVisitorInterface {
		
		/**
		 * The specific range that this visitor is collecting references for.
		 * Only identifiers that reference this range will be processed.
		 * @var AstRange
		 */
		private AstRange $targetRange;
		
		/**
		 * The default context for references when no specific context can be determined
		 * from the AST hierarchy (typically 'SELECT' or 'WHERE').
		 * @var string
		 */
		private string $context;
		
		/**
		 * Initializes the visitor with the target range and default context.
		 * @param AstRange $range The range node to collect references for
		 * @param string $context Default context to use when context cannot be determined from AST
		 */
		public function __construct(AstRange $range, string $context) {
			$this->targetRange = $range;
			$this->context = $context;
		}
		
		/**
		 * Visits each node in the AST and collects references to the target range.
		 * @param AstInterface $node The current AST node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Only process identifier nodes - other node types don't represent data references
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip identifiers that don't have an associated range
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip identifiers that don't reference our target range
			if ($node->getRange() !== $this->targetRange) {
				return;
			}
			
			// Only process base identifiers (not derived or computed identifiers)
			// Base identifiers represent direct field/column references
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			// Create and add a reference object based on the identifier's context
			$this->targetRange->addReference($this->createReference($node));
		}
		
		/**
		 * Creates a Reference object of the appropriate type based on the identifier's context.
		 * @param AstIdentifier $node The identifier node to create a reference for
		 * @return Reference A Reference object of the appropriate subtype
		 * @throws \InvalidArgumentException If an unknown context is determined
		 */
		private function createReference(AstIdentifier $node): Reference {
			return match($this->context) {
				'SELECT' => new ReferenceSelect($node),
				'WHERE' => new ReferenceWhere($node),
				'AGGREGATE' => new ReferenceAggregate($node),
				'AGGREGATE_WHERE' => new ReferenceAggregateWhere($node),
			};
		}
		
		/**
		 * Checks if a given AST node represents an aggregate function.
		 * @param AstInterface $node The node to check
		 * @return bool True if the node is an aggregate function, false otherwise
		 */
		private function isAggregateNode(AstInterface $node): bool {
			return
				$node instanceof AstAvg ||      // Average function
				$node instanceof AstAvgU ||     // Unsigned average function
				$node instanceof AstCount ||    // Count function
				$node instanceof AstCountU ||   // Unsigned count function
				$node instanceof AstSum ||      // Sum function
				$node instanceof AstSumU ||     // Unsigned sum function
				$node instanceof AstMax ||      // Maximum function
				$node instanceof AstMin ||      // Minimum function
				$node instanceof AstAny;        // Existential (ANY) function
		}
	}