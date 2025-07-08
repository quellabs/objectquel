<?php
	
	namespace Quellabs\ObjectQuel\Visitor;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that classifies query conditions for staged execution
	 * Categorizes conditions into first-stage filters, join conditions, cross filters, and post filters
	 */
	class StagedExecutionConditionVisitor implements AstVisitorInterface {
		/**
		 * Range alias to source type mapping (json/database)
		 * @var array
		 */
		private array $rangeMapping;
		
		/**
		 * Conditions that can be applied directly to the first data source
		 * @var array
		 */
		private array $firstStageFilters = [];
		
		/**
		 * Equality conditions that join different data sources
		 * @var array
		 */
		private array $joinConditions = [];
		
		/**
		 * Non-equality conditions that reference multiple data source types
		 * @var array
		 */
		private array $crossFilters = [];
		
		/**
		 * Conditions that must be applied after joining the data sources
		 * @var array
		 */
		private array $postFilters = [];
		
		/**
		 * @param array $rangeMapping Mapping of range aliases to their source types
		 */
		public function __construct(array $rangeMapping) {
			$this->rangeMapping = $rangeMapping;
		}
		
		/**
		 * Visits an AST node and classifies it into the appropriate condition category
		 * @param AstInterface $node The current node being visited
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			if ($node instanceof AstBinaryOperator) {
				$this->processBinaryOperator($node);
			} else {
				$this->processGenericNode($node);
			}
		}
		
		/**
		 * Processes a binary operator node (AND, OR, =, >, etc.)
		 * @param AstBinaryOperator $node
		 * @return void
		 */
		private function processBinaryOperator(AstBinaryOperator $node): void {
			if ($node->getOperator() === 'AND') {
				// For AND, we can process each side independently
				// This is handled by the AST traversal, so we don't need special logic here
				return;
			} elseif ($node->getOperator() === 'OR') {
				// For OR, we need to check if all referenced ranges are of the same type
				$ranges = $this->getReferencedRanges($node);
				$allSameType = $this->allRangesSameType($ranges);
				
				if ($allSameType) {
					$type = $this->rangeMapping[$ranges[0]];
					$this->firstStageFilters[] = [
						'source'    => $type,
						'condition' => $node
					];
				} else {
					$this->postFilters[] = $node;
				}
			} elseif ($node->getOperator() === '=' || $node->getOperator() === '==') {
				// Check if this is a join condition between different source types
				if ($this->isJoinCondition($node)) {
					$this->addJoinCondition($node);
				} else {
					$this->classifyBasedOnRanges($node);
				}
			} else {
				// For other operators (>, <, !=, etc.)
				$this->classifyBasedOnRanges($node);
			}
		}
		
		/**
		 * Processes a non-binary operator node
		 * @param AstInterface $node
		 * @return void
		 */
		private function processGenericNode(AstInterface $node): void {
			$this->classifyBasedOnRanges($node);
		}
		
		/**
		 * Classifies a node based on the ranges it references
		 * @param AstInterface $node
		 * @return void
		 */
		private function classifyBasedOnRanges(AstInterface $node): void {
			$ranges = $this->getReferencedRanges($node);
			
			// If no ranges are referenced, we can't classify
			if (empty($ranges)) {
				return;
			}
			
			$hasJsonRange = false;
			$hasDbRange = false;
			
			foreach ($ranges as $range) {
				$type = $this->rangeMapping[$range] ?? null;
				
				if ($type === 'json') {
					$hasJsonRange = true;
				} else if ($type === 'database') {
					$hasDbRange = true;
				}
			}
			
			if ($hasJsonRange && $hasDbRange) {
				$this->crossFilters[] = $node;
			} else if ($hasJsonRange) {
				$this->firstStageFilters[] = ['source' => 'json', 'condition' => $node];
			} else if ($hasDbRange) {
				$this->firstStageFilters[] = ['source' => 'database', 'condition' => $node];
			}
		}
		
		/**
		 * Determines if a binary operator is a join condition between different source types
		 * @param AstBinaryOperator $node
		 * @return bool
		 */
		private function isJoinCondition(AstBinaryOperator $node): bool {
			$leftRange = $this->getRangeAlias($node->getLeft());
			$rightRange = $this->getRangeAlias($node->getRight());
			
			if (!$leftRange || !$rightRange || $leftRange === $rightRange) {
				return false;
			}
			
			$leftType = $this->rangeMapping[$leftRange] ?? null;
			$rightType = $this->rangeMapping[$rightRange] ?? null;
			
			return $leftType !== $rightType;
		}
		
		/**
		 * Adds a join condition to the list
		 * @param AstBinaryOperator $node
		 * @return void
		 */
		private function addJoinCondition(AstBinaryOperator $node): void {
			$leftRange = $this->getRangeAlias($node->getLeft());
			$rightRange = $this->getRangeAlias($node->getRight());
			
			$leftType = $this->rangeMapping[$leftRange];
			$rightType = $this->rangeMapping[$rightRange];
			
			$this->joinConditions[] = [
				'left'     => [
					'range' => $leftRange,
					'field' => $this->getFieldName($node->getLeft()),
					'type'  => $leftType
				],
				'right'    => [
					'range' => $rightRange,
					'field' => $this->getFieldName($node->getRight()),
					'type'  => $rightType
				],
				'operator' => $node->getOperator(),
				'node'     => $node
			];
		}
		
		/**
		 * Returns all range aliases referenced by a node
		 * @param AstInterface $node
		 * @return array
		 */
		private function getReferencedRanges(AstInterface $node): array {
			$visitor = new GatherReferencedRanges();
			$node->accept($visitor);
			return $visitor->getRanges();
			
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&
				$ast->getRange() instanceof AstRangeDatabase &&
				!$ast->hasNext()
			);
		}
		
		/**
		 * Gets the range alias from an expression node
		 * Extracts the range alias from an identifier node by accessing its parent entity
		 * @param AstInterface $node The AST node to extract the range alias from
		 * @return string|null The range alias if found, null otherwise
		 */
		private function getRangeAlias(AstInterface $node): ?string {
			// We can only extract range aliases from identifier nodes
			if (!$node instanceof AstIdentifier) {
				return null;
			}
			
			// The identifier must have a range
			if (!$node->hasRange()) {
				return null;
			}
			
			// Get the range
			$range = $node->getRange();
			
			// Return the name of the range
			return $range->getName();
		}
		
		/**
		 * Gets the field name from an expression node
		 * @param AstInterface $node
		 * @return string|null
		 */
		private function getFieldName(AstInterface $node): ?string {
			// Implementeer logica om veldnaam te extraheren
			// Dit is een placeholder
			return null;
		}
		
		/**
		 * Checks if all ranges are of the same source type
		 *
		 * @param array $ranges
		 * @return bool
		 */
		private function allRangesSameType(array $ranges): bool {
			if (empty($ranges)) {
				return true;
			}
			
			$firstType = $this->rangeMapping[$ranges[0]] ?? null;
			
			foreach ($ranges as $range) {
				$type = $this->rangeMapping[$range] ?? null;
				
				if ($type !== $firstType) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Returns the collected condition classifications
		 *
		 * @return array
		 */
		public function getResults(): array {
			return [
				'firstStageFilters' => $this->firstStageFilters,
				'joinConditions'    => $this->joinConditions,
				'crossFilters'      => $this->crossFilters,
				'postFilters'       => $this->postFilters
			];
		}
	}