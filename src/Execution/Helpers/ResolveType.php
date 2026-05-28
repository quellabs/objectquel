<?php
	
	namespace Quellabs\ObjectQuel\Execution\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * This class provides functionality to analyze Abstract Syntax Tree (AST) nodes
	 * and determine their data types based on entity annotations and type precedence rules.
	 * It's primarily used in query processing to ensure type safety and proper casting.
	 */
	class ResolveType {
		
		/**
		 * Entity store containing metadata and annotations for all entities
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * Temporal type promotion table.
		 *
		 * Format:
		 *   [operator][leftType][rightType] => resultType
		 *
		 * @var array<string, array<string, array<string, string>>>
		 */
		private const array TEMPORAL_TYPE_TABLE = [
			'+' => [
				'datetime' => [
					'datetime' => 'datetime',
					'interval' => 'datetime',
				],
				'interval' => [
					'datetime' => 'datetime',
					'interval' => 'interval',
				],
			],
			
			'-' => [
				'datetime' => [
					'datetime' => 'interval',
					'interval' => 'datetime',
				],
				'interval' => [
					'datetime' => 'interval',
					'interval' => 'interval',
				],
			],
		];
		
		/**
		 * Constructor - initializes the helper with an entity store
		 * @param EntityStore $entityStore Store containing entity metadata and annotations
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Recursively infers the return type of AST node and its children
		 *
		 * This method traverses the AST tree and determines the resulting data type
		 * based on node types and type precedence rules. It handles:
		 * - Direct identifier lookups
		 * - Binary operations (terms and factors)
		 * - Type promotion (float > string > other types)
		 *
		 * @param AstInterface $ast The AST node to analyze
		 * @return string|null The inferred PHP type (e.g., 'float', 'string', 'int') or null if undetermined
		 * @throws EntityResolutionException
		 */
		public function inferReturnType(AstInterface $ast): ?string {
			// Process identifiers - lookup their type from entity annotations
			if ($ast instanceof AstIdentifier) {
				return $this->inferReturnTypeOfIdentifier($ast);
			}
			
			// Aggregates (SUM, AVG, MIN, MAX, and their DISTINCT variants) produce the
			// same type as their argument. SUM(floatCol) is float, MIN(intCol) is integer.
			// AstCount/AstCountU are intentionally excluded — they always return integer
			// regardless of the argument type, and they declare that via getReturnType().
			if ($ast instanceof AstAggregate) {
				return $this->inferReturnType($ast->getIdentifier());
			}
			
			// Unary sign operators (+x, -x) do not change the numeric type of the operand.
			if ($ast instanceof AstUnaryOperation) {
				return $this->inferReturnType($ast->getExpression());
			}
			
			// Traverse down the parse tree for binary operations (terms/factors)
			if ($ast instanceof NodeBinary) {
				// Recursively get types of left and right operands
				$left = $this->inferReturnType($ast->getLeft());
				$right = $this->inferReturnType($ast->getRight());
				
				// If either operand is float, result is float (highest precision)
				if (
					$left === 'datetime' || $left === 'interval' ||
					$right === 'datetime' || $right === 'interval'
				) {
					return self::TEMPORAL_TYPE_TABLE[$ast->getOperator()][$left][$right] ?? null;
				}
				
				// If either operand is float, result is float (highest precision)
				if ($left === "float" || $right === "float") {
					return 'float';
				}
				
				// If either operand is string, result is string (concatenation/coercion)
				if ($left === "string" || $right === "string") {
					return 'string';
				}
				
				// Otherwise, use the left operand's type as default
				return $left;
			}
			
			// Fallback: use the node's own declared return type if available.
			// Covers AstCount, AstCountU, AstBool, AstNull, AstString, AstNumber, etc.
			// Nodes without getReturnType() (AstCase, AstTernary, AstIfNull) return null
			// here, which causes the caller to fall back to a safe runtime REGEXP.
			return $ast->getReturnType();
		}
		
		/**
		 * Determines the return type of the identifier by checking its ORM annotations
		 *
		 * This method looks up the entity's column annotations to determine the PHP type
		 * that corresponds to the database column type. It searches through all annotations
		 * on the identifier to find a Column annotation containing type information.
		 *
		 * @param AstIdentifier $identifier The identifier node to analyze
		 * @return string|null The PHP type mapped from the database column type, or null if not found
		 * @throws EntityResolutionException
		 */
		public function inferReturnTypeOfIdentifier(AstIdentifier $identifier): ?string {
			// Fetch the entity name
			$entityName = $identifier->getEntityName();
			
			// If none found, bail
			if ($entityName === null) {
				return null;
			}
			
			// Get all annotations for the entity that this identifier belongs to
			$metadata = $this->entityStore->getMetadata($entityName);
			$annotationList = $metadata->getAnnotations();
			
			// Check if this specific identifier has any annotations
			if (!isset($annotationList[$identifier->getName()])) {
				return null; // No annotations found for this identifier
			}
			
			// Search through all annotations on this identifier
			foreach ($annotationList[$identifier->getName()] as $annotation) {
				// Look specifically for Column annotations which contain type information
				if ($annotation instanceof Column) {
					// Convert the database column type (Phinx format) to PHP type
					return TypeMapper::phinxTypeToPhpType($annotation->getType());
				}
			}
			
			// No Column annotation found
			return null;
		}
	}