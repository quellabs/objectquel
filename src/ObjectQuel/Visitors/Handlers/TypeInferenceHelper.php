<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * This class provides functionality to analyze Abstract Syntax Tree (AST) nodes
	 * and determine their data types based on entity annotations and type precedence rules.
	 * It's primarily used in query processing to ensure type safety and proper casting.
	 */
	class TypeInferenceHelper {

		/**
		 * Entity store containing metadata and annotations for all entities
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
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
		 */
		public function inferReturnType(AstInterface $ast): ?string {
			// Process identifiers - lookup their type from entity annotations
			if ($ast instanceof AstIdentifier) {
				return $this->inferReturnTypeOfIdentifier($ast);
			}
			
			// Traverse down the parse tree for binary operations (terms/factors)
			if ($ast instanceof AstTerm || $ast instanceof AstFactor) {
				// Recursively get types of left and right operands
				$left = $this->inferReturnType($ast->getLeft());
				$right = $this->inferReturnType($ast->getRight());
				
				// Apply type precedence rules for binary operations:
				// 1. If either operand is float, result is float (highest precision)
				if (($left === "float") || ($right === "float")) {
					return 'float';
					// 2. If either operand is string, result is string (concatenation/coercion)
				} elseif (($left === "string") || ($right === "string")) {
					return 'string';
					// 3. Otherwise, use the left operand's type as default
				} else {
					return $left;
				}
			}
			
			// Fallback: use the node's own declared return type if available
			return $ast->getReturnType();
		}
		
		/**
		 * Determines the return type of an identifier by checking its ORM annotations
		 *
		 * This method looks up the entity's column annotations to determine the PHP type
		 * that corresponds to the database column type. It searches through all annotations
		 * on the identifier to find a Column annotation containing type information.
		 *
		 * @param AstIdentifier $identifier The identifier node to analyze
		 * @return string|null The PHP type mapped from the database column type, or null if not found
		 */
		public function inferReturnTypeOfIdentifier(AstIdentifier $identifier): ?string {
			// Get all annotations for the entity that this identifier belongs to
			$annotationList = $this->entityStore->getAnnotations($identifier->getEntityName());
			
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