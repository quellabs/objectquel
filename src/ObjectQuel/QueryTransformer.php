<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddNamespacesToEntities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddRangeToEntityWhenItsMissing;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPlugMacros;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessMacro;
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
			// Step 1: Plug macro placeholders into the AST structure
			// This visitor finds macro references and creates placeholder nodes for later expansion
			$this->processWithVisitor($ast, EntityPlugMacros::class, $ast->getMacros());
			
			// Step 2: Process range definitions (table joins, aliases, and FROM clauses)
			// Converts range specifications into proper join conditions and table references
			$this->processWithVisitor($ast, EntityProcessRange::class, $ast->getRanges());
			
			// Step 3: Expand macro definitions with their actual implementations
			// Replaces macro placeholder nodes with the full macro body/logic
			$this->processWithVisitor($ast, EntityProcessMacro::class, $ast->getMacros());
			
			// Step 4: Automatically add missing table ranges for referenced entities
			// Analyzes field references and adds necessary JOIN clauses for tables not explicitly included
			$this->plugMissingRanges($ast);
			
			// Step 5: Add proper namespaces to all entity references
			// Resolves entity names to their fully qualified forms using the entity store
			$this->processWithVisitor($ast, AddNamespacesToEntities::class, $this->entityStore, $ast->getRanges(), $ast->getMacros());
			
			// Step 6: Transform complex 'via' relationships into direct property lookups
			// Converts indirect relationships through intermediate entities into direct SQL joins
			$this->transformViaRelations($ast);
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
		 * Adds missing ranges when users directly reference entity classes without explicit range aliases.
		 *
		 * This method handles the transformation from direct entity class usage to proper range-based queries.
		 * When users write queries like `retrieve(PostEntity)`, this method automatically creates the
		 * necessary range with a generated alias, transforming it to `range(p001 is PostEntity) retrieve(p001)`.
		 *
		 * Process:
		 * 1. Scan the AST for direct entity class references (e.g., PostEntity, UserEntity)
		 * 2. For each direct entity reference found, generate a unique range alias (e.g., p001, u002)
		 * 3. Create a new range definition that maps the alias to the entity class
		 * 4. Replace the direct entity reference with the generated range alias
		 *
		 * Example transformation:
		 * Before: `retrieve(PostEntity)`
		 * After:  `range of p001 is PostEntity; retrieve(p001)`
		 *
		 * @param AstRetrieve $ast The query AST to analyze and modify
		 * @return void Modifies the AST by adding missing ranges and updating entity references
		 */
		private function plugMissingRanges(AstRetrieve $ast): void {
			// Use a specialized visitor to traverse the AST and identify missing entity references
			// AddRangeToEntityWhenItsMissing analyzes field references like "user.name"
			// and determines if "user" table is properly joined in the query
			$processor = $this->processWithVisitor($ast, AddRangeToEntityWhenItsMissing::class, $this->entityStore);
			
			// Retrieve all the missing ranges that the visitor discovered during traversal
			// Each range represents a table that needs to be joined to satisfy field references
			// For example, if query references "user.email" but user table isn't joined,
			// processor will create a range for the user table
			foreach ($processor->getRanges() as $range) {
				// Add the missing range/table to the query's range collection
				// This effectively adds the table to the FROM/JOIN clause of the eventual SQL
				// The range contains join conditions, table aliases, and relationship information
				$ast->addRange($range);
			}
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