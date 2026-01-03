<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityNameNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeDatabaseEntityNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\MacroSubstitutor;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\MacroExpander;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\TransformRelationInViaToPropertyLookup;
	
	/**
	 * This class orchestrates a multi-step transformation process that converts high-level
	 * ObjectQuel queries into a format suitable for SQL generation. The transformation
	 * handles macro expansion, range processing, namespace resolution, and relationship
	 * mapping through a series of visitor pattern implementations.
	 */
	class QueryTransformer {
		
		/**
		 * Entity store containing metadata about all available entities and their relationships.
		 * Used for namespace resolution, relationship mapping, and schema validation.
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * Initialize the query transformer with an entity store.
		 * @param EntityStore $entityStore Store containing entity definitions and metadata
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Transform an ObjectQuel AST into SQL-ready format through multi-stage processing.
		 * @param AstRetrieve $ast The parsed ObjectQuel query AST to transform
		 * @return void Modifies the AST in-place
		 */
		public function transform(AstRetrieve $ast): void {
			// First, recursively transform all nested queries in temporary ranges
			// This ensures inner queries are fully resolved before outer query processing
			$this->transformNestedQueries($ast);
			
			// Step 1: Plug macro placeholders into the AST structure
			// This visitor finds macro references and creates placeholder nodes for later expansion
			$this->processWithVisitor($ast, MacroSubstitutor::class, $ast->getMacros());
			
			// Step 2: Add proper namespaces to all ranges
			// Resolves entity names to their fully qualified forms using the entity store
			$this->processWithVisitor($ast, RangeDatabaseEntityNormalizer::class, $this->entityStore);
			
			// Step 3: Process range definitions (table joins, aliases, and FROM clauses)
			// Converts range specifications into proper join conditions and table references
			$this->processWithVisitor($ast, EntityProcessRange::class, $ast->getRanges());
			
			// Step 4: Expand macro definitions with their actual implementations
			// Replaces macro placeholder nodes with the full macro body/logic
			$this->processWithVisitor($ast, MacroExpander::class, $ast->getMacros());
			
			// Step 5: Add proper namespaces to all entity references
			// Resolves entity names to their fully qualified forms using the entity store
			$this->processWithVisitor($ast, EntityNameNormalizer::class, $this->entityStore, $ast->getRanges(), $ast->getMacros());
			
			// Step 6: Transform complex 'via' relationships into direct property lookups
			// Converts indirect relationships through intermediate entities into direct SQL joins
			$this->transformViaRelations($ast);
		}
		
		/**
		 * Recursively transform all nested queries in temporary range definitions.
		 * Ensures that inner queries are fully resolved before the outer query is processed.
		 * @param AstRetrieve $ast The query AST containing potential nested queries
		 * @return void Modifies nested queries in-place
		 */
		private function transformNestedQueries(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				// Only process temporary ranges that contain nested queries
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				if ($range->getQuery() === null) {
					continue;
				}
				
				// Recursively transform the inner query with full transformation pipeline
				$this->transform($range->getQuery());
			}
		}
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param string $visitorClass The fully qualified visitor class name
		 * @param mixed ...$args Variable arguments to pass to the visitor constructor
		 * @return object The visitor instance after processing (useful for accessing results)
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): object {
			// Create a new instance of the specified visitor class with provided arguments
			$visitor = new $visitorClass(...$args);
			
			// Apply the visitor to the AST using the visitor pattern
			// The AST will traverse itself and call appropriate visitor methods
			$ast->accept($visitor);
			
			// Return the visitor instance in case caller needs access to results or state
			return $visitor;
		}
		
		/**
		 * Transforms complex 'via' relations into simple property lookups for SQL generation.
		 * @param AstRetrieve $ast The query AST containing ranges with potential 'via' relations
		 * @return void Modifies ranges in-place to replace 'via' relations with direct lookups
		 */
		private function transformViaRelations(AstRetrieve $ast): void {
			// Process each table/range in the query to handle 'via' relationship definitions
			foreach ($ast->getRanges() as $range) {
				// Get the join property that defines how this range connects to other tables
				// Join properties specify the relationship/connection logic between entities
				$joinProperty = $range->getJoinProperty();
				
				// Skip ranges that don't have join properties
				// The main/root table typically doesn't need join properties
				// as it's the starting point for the query (appears in FROM clause)
				if ($joinProperty === null) {
					continue;
				}
				
				// Create a specialized converter to transform 'via' relations into direct property references
				// This converter understands the entity relationships stored in the EntityStore
				// and can resolve indirect relationship chains into direct field mappings
				$converter = new TransformRelationInViaToPropertyLookup($this->entityStore, $range);
				
				// Transform the join property itself to resolve any 'via' relationships
				// This converts complex relationship definitions into simple field-to-field mappings
				// The result is a join condition that SQL can understand and execute efficiently
				$range->setJoinProperty($converter->processNodeSide($joinProperty));
				
				// Apply the converter to the entire range to handle any other 'via' references
				// This ensures all parts of the range definition (filters, conditions, etc.)
				// are properly transformed and don't contain unresolved 'via' relationships
				$range->accept($converter);
			}
		}
	}