<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeDatabaseProxyResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\MacroPlaceholderSetter;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\TransformRelationInViaToPropertyLookup;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\UnqualifiedDatabasePropertyResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DiscriminatorConditionInjector;
	
	/**
	 * This class orchestrates a multi-step transformation process that converts high-level
	 * ObjectQuel queries into a format suitable for SQL generation. The transformation
	 * handles macro expansion, range processing, namespace resolution, and relationship
	 * mapping through a series of visitor pattern implementations.
	 */
	class SemanticAnalyserPrefilter {
		
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
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		public function transform(AstRetrieve $ast): void {
			// First, recursively transform all nested queries in temporary ranges
			// This ensures inner queries are fully resolved before outer query processing
			$this->transformNestedQueries($ast);
			
			// Step 1: Plug macro placeholders into the AST structure
			// This visitor finds macro references and creates placeholder nodes for later expansion
			$this->processWithVisitor($ast, MacroPlaceholderSetter::class, $ast->getMacros());
			
			// Step 2: Add proper namespaces to all ranges
			// Resolves entity names to their fully qualified forms using the entity store
			$this->processWithVisitor($ast, RangeDatabaseProxyResolver::class, $this->entityStore);
			
			// Step 2.5: Inject discriminator conditions for single-table inheritance
			// Iterates ranges directly — no need for a full AST traversal since
			// ranges only ever appear in AstRetrieve::$ranges.
			$this->injectDiscriminatorConditions($ast);

			// Step 3: Resolve unqualified property names to range-prefixed identifiers
			// Allows bare names like 'name' to be written instead of 'p.name' when unambiguous
			//$this->processWithVisitor($ast, UnqualifiedDatabasePropertyResolver::class, $this->entityStore, $ast->getRanges());
			
			// Step 4: Expand macro definitions with their actual implementations
			// Replaces macro placeholder nodes with the full macro body/logic
			$this->processWithVisitor($ast, MacroPlaceholderSetter::class, $ast->getMacros());
			
			// Step 6: Converts indirect relationships through intermediate entities into direct joins
			$this->transformViaRelations($ast);
		}
		
		/**
		 * Recursively transform all nested queries in temporary range definitions.
		 * Ensures that inner queries are fully resolved before the outer query is processed.
		 * @param AstRetrieve $ast The query AST containing potential nested queries
		 * @return void Modifies nested queries in-place
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function transformNestedQueries(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				// Only process temporary ranges that contain nested queries
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				// Recursively transform the inner query with full transformation pipeline
				$this->transform($range->getQuery());
			}
		}
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param class-string<AstVisitorInterface> $visitorClass The fully qualified visitor class name
		 * @param mixed ...$args Variable arguments to pass to the visitor constructor
		 * @return void
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): void {
			// Create a new instance of the specified visitor class with provided arguments
			$visitor = new $visitorClass(...$args);
			
			// Apply the visitor to the AST using the visitor pattern
			// The AST will traverse itself and call appropriate visitor methods
			$ast->accept($visitor);
		}
		
		/**
		 * Injects discriminator conditions into the WHERE clause for STI subclass ranges.
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws TransformationException
		 */
		private function injectDiscriminatorConditions(AstRetrieve $ast): void {
			$injector = new DiscriminatorConditionInjector($this->entityStore);

			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}

				$injector->process($range, $ast);
			}
		}
		
		/**
		 * Transforms complex 'via' relations into simple property lookups for SQL generation.
		 * @param AstRetrieve $ast The query AST containing ranges with potential 'via' relations
		 * @return void Modifies ranges in-place to replace 'via' relations with direct lookups
		 * @throws TransformationException|EntityResolutionException
		 */
		private function transformViaRelations(AstRetrieve $ast): void {
			// Process each table/range in the query to handle 'via' relationship definitions
			foreach ($ast->getRanges() as $range) {
				// Only handle database ranges
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
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
				
				// Explicitly traverse the new join property rather than using range->accept(),
				// which would cause infinite recursion since identifiers hold back-references
				// to their range and AstRangeDatabase::accept() previously traversed joinProperty.
				$range->getJoinProperty()?->accept($converter);
			}
		}
	}