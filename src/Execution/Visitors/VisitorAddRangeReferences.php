<?php
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;
	
	use Quellabs\ObjectQuel\Execution\RangeReferences\Reference;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregate;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregateWhere;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceOrderBy;
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
		 * The parent context for aggregation contexts
		 * @var string|null
		 */
		private ?string $parentContext;

		/**
		 * List of nodes we already visited
		 * @var array
		 */
		private static array $visitedNodes = [];
		
		/**
		 * Initializes the visitor with the target range and default context.
		 * @param AstRange $range The range node to collect references for
		 * @param string $context Default context to use when context cannot be determined from AST
		 * @param string|null $parentContext Parent context
		 */
		public function __construct(AstRange $range, string $context, ?string $parentContext=null) {
			$this->targetRange = $range;
			$this->context = $context;
			$this->parentContext = $parentContext;
		}
		
		/**
		 * Visits each node in the AST and collects references to the target range.
		 * @param AstInterface $node The current AST node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Get object ID
			$nodeId = spl_object_id($node);
			
			// Already processed this node
			if (isset(self::$visitedNodes[$nodeId])) {
				return;
			}
			
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
			
			// Only process base identifiers
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			// Create and add a reference object based on the identifier's context
			if ($this->context === 'AGGREGATE_WHERE') {
				// Special case for AGGREGATE_WHERE
				$reference = $this->createReference($node, $this->context, $this->parentContext);
				$this->targetRange->addReference($reference);
				
				// Add node to visitedNodes list
				self::$visitedNodes[$nodeId] = true;
				return;
			}
			
			if ($node->parentIsOneOf([
				AstMin::class, AstMax::class,
				AstAvg::class, AstAvgU::class,
				AstCount::class, AstCountU::class,
				AstSum::class, AstSumU::class,
			])) {
				$reference = $this->createReference($node, "AGGREGATE", $this->context);
			} else {
				$reference = $this->createReference($node, $this->context);
			}
			
			// Add the reference
			$this->targetRange->addReference($reference);
			
			// Add node to visitedNodes list
			self::$visitedNodes[$nodeId] = true;
		}
		
		public static function resetVisitedNodes(): void {
			self::$visitedNodes = [];
		}
		
		/**
		 * Creates a Reference object of the appropriate type based on the identifier's context.
		 * @param AstIdentifier $node The identifier node to create a reference for
		 * @param string $type
		 * @param string|null $parentContext
		 * @return Reference A Reference object of the appropriate subtype
		 * @throws \InvalidArgumentException If an unknown context is determined
		 */
		private function createReference(AstIdentifier $node, string $type, ?string $parentContext = null): Reference {
			return match ($type) {
				'SELECT' => new ReferenceSelect($node),
				'WHERE' => new ReferenceWhere($node),
				'AGGREGATE' => new ReferenceAggregate($node, $parentContext),
				'AGGREGATE_WHERE' => new ReferenceAggregateWhere($node, $parentContext),
				'ORDER_BY' => new ReferenceOrderBy($node),
			};
		}
	}