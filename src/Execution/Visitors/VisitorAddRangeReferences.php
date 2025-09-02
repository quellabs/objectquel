<?php
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;
	
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregate;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregateWhere;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceOrderBy;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceSelect;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceWhere;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that collects and categorizes range references for query execution planning.
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
		public function __construct(AstRange $range, string $context, ?string $parentContext = null) {
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
			
			// Fetch the parent aggregate
			$parentAggregate = $node->getParentAggregate();

			// Determine context based on explicit context and AST structure
			if ($this->context === "AGGREGATE_WHERE") {
				$context = "AGGREGATE_WHERE";
			} elseif ($parentAggregate !== null) {
				$context = "AGGREGATE";
			} else {
				$context = $this->context;
			}
			
			// Create the reference
			$reference = match ($context) {
				'SELECT' => new ReferenceSelect($node),
				'WHERE' => new ReferenceWhere($node),
				'ORDER_BY' => new ReferenceOrderBy($node),
				'AGGREGATE' => new ReferenceAggregate($node, $this->context, $parentAggregate),
				'AGGREGATE_WHERE' => new ReferenceAggregateWhere($node, $this->parentContext, $parentAggregate),
			};
			
			// Add the reference
			$this->targetRange->addReference($reference);
			
			// Add node to visitedNodes list
			self::$visitedNodes[$nodeId] = true;
		}
		
		/**
		 * Clear visited nodes list
		 * @return void
		 */
		public static function resetVisitedNodes(): void {
			self::$visitedNodes = [];
		}
	}