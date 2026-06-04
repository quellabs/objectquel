<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Planner\Helpers\InjectDiscriminatorCondition;
	use Quellabs\ObjectQuel\Planner\Visitors\SearchStrategyResolver;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Main orchestrator that coordinates all query optimization strategies.
	 * Acts as a facade that delegates to specialized optimizers.
	 */
	class QueryOptimizer {
		
		// EntityStore for metadata
		private EntityStore $entityStore;
		
		// Core optimization strategies - each handles a specific type of optimization
		private Optimizers\DatabaseRangePromotor $rangePromotor;             // Subquery to temp/materialized nodes
		private Optimizers\AnyOptimizer $anyOptimizer;                       // Optimize ANY statements
		private Optimizers\RangeOptimizer $rangeOptimizer;                   // Optimizes range queries and filtering
		private Optimizers\JoinOptimizer $joinOptimizer;                     // Handles JOIN operations and elimination
		private Optimizers\AggregateOptimizer $aggregateOptimizer;           // Optimizes aggregate functions (COUNT, SUM, etc.)
		private Optimizers\ExistsOptimizer $existsOptimizer;                 // Converts EXISTS subqueries to more efficient forms
		private Optimizers\JoinConditionFieldInjector $JoinConditionFieldInjector; // Optimizes value references and constants
		private Optimizers\FoldingRuleOptimizer $constantFoldingOptimizer;          // Folds statically-resolvable nodes to boolean constants
		private Optimizers\BooleanConstantOptimizer $booleanConstantOptimizer;           // Collapses boolean constants through AND / OR / NOT / comparisons
		private Visitors\SearchStrategyResolver $searchResolver;
		
		/**
		 * Initialize all optimizer components with shared EntityManager dependency.
		 * @param EntityManager $entityManager Provides metadata about entities/tables for optimization decisions
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->entityStore = $entityManager->getEntityStore();
			
			// Initialize optimizers that need entity metadata
			$this->rangePromotor = new Optimizers\DatabaseRangePromotor();
			$this->anyOptimizer = new Optimizers\AnyOptimizer($entityManager);
			$this->rangeOptimizer = new Optimizers\RangeOptimizer($entityManager);
			$this->joinOptimizer = new Optimizers\JoinOptimizer($entityManager);
			$this->aggregateOptimizer = new Optimizers\AggregateOptimizer($platform);
			
			// Initialize stateless optimizers that work on AST structure alone
			$this->existsOptimizer = new Optimizers\ExistsOptimizer();
			$this->JoinConditionFieldInjector = new Optimizers\JoinConditionFieldInjector();
			$this->booleanConstantOptimizer = new Optimizers\BooleanConstantOptimizer();
			$this->searchResolver = new SearchStrategyResolver($entityManager->getEntityStore());

			// Constant folder
			$this->constantFoldingOptimizer = new Optimizers\FoldingRuleOptimizer([
				new Optimizers\FoldingRules\TypeCheckFoldingRule($entityManager),
			]);
		}
		
		/**
		 * Optimize the query using all available optimization strategies.
		 * Applies optimizations recursively to nested queries first (depth-first),
		 * then optimizes the outer query.
		 * @param AstRetrieve $ast The query AST to optimize in-place
		 * @param array<string, mixed> $parameters Runtime query parameters
		 * @param PlanLogInterface $log Collects planning decisions; use NullPlanLog to disable
		 * @throws QuelException|EntityResolutionException|TransformationException
		 */
		public function transform(AstRetrieve $ast, array $parameters, PlanLogInterface $log = new NullPlanLog()): void {
			// First, recursively transform all nested queries in temporary ranges
			// This ensures inner queries are fully resolved before outer query processing
			$this->transformNestedQueries($ast, $parameters, $log);
			
			// Step 2.5: Inject discriminator conditions for single-table inheritance
			// Iterates ranges directly — no need for a full AST traversal since
			// ranges only ever appear in AstRetrieve::$ranges.
			$this->injectDiscriminatorConditions($ast);
			
			// Complete all database range entity namespaces
			$this->resolveEntityNamespaces($ast);
			
			// Phase 1: Basic range and relationship optimizations
			// Apply filtering early to reduce dataset size for subsequent operations
			$this->constantFoldingOptimizer->optimize($ast);
			$this->booleanConstantOptimizer->optimize($ast);
			$this->rangePromotor->optimize($ast, $log);
			$this->rangeOptimizer->optimize($ast, $log);
			
			// Phase 2: Remove left joins that are not referenced in the query
			$this->rangeOptimizer->removeUnusedLeftJoinRanges($ast, true, $log);
			$this->rangeOptimizer->removeUnusedTemporaryRanges($ast, $log);
			
			// Phase 3: Optimize joins
			$this->joinOptimizer->optimize($ast, $log);
			
			// Phase 4: Subquery and aggregate optimizations
			// Convert EXISTS to JOINs where beneficial, then optimize aggregates
			// These may create new optimization opportunities for previous phases
			$this->existsOptimizer->optimize($ast, $log);
			$this->anyOptimizer->optimize($ast);
			$this->aggregateOptimizer->optimize($ast, $log);
			
			// Convert search(...) to like/fulltext node
			$this->searchResolver->resolve($ast, $parameters, $log);
			
			// Phase 5: Final cleanup
			// Remove LEFT JOIN ranges that became unreferenced after Phase 4 rewrites
			// (e.g. AnyOptimizer moved a range entirely into a correlated subquery).
			// JoinConditionFieldInjector runs last so it sees the final range set.
			$this->rangeOptimizer->removeUnusedLeftJoinRanges($ast, false, $log);
			$this->JoinConditionFieldInjector->optimize($ast);
		}
		
		/**
		 * Recursively transform all nested queries in temporary range definitions.
		 * Ensures that inner queries are fully resolved before the outer query is processed.
		 * @param AstRetrieve $ast The query AST containing potential nested queries
		 * @param array<string, mixed> $parameters Runtime query parameters
		 * @return void Modifies nested queries in-place
		 * @throws TransformationException
		 * @throws EntityResolutionException|QuelException
		 */
		private function transformNestedQueries(AstRetrieve $ast, array $parameters, PlanLogInterface $log): void {
			foreach ($ast->getRanges() as $range) {
				// Only process temporary ranges that contain nested queries
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				// Recursively transform the inner query with full transformation pipeline
				$this->transform($range->getQuery(), $parameters, $log);
			}
		}
		
		/**
		 * Injects discriminator conditions into the WHERE clause for STI subclass ranges.
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws TransformationException
		 */
		private function injectDiscriminatorConditions(AstRetrieve $ast): void {
			$injector = new InjectDiscriminatorCondition($this->entityStore);
			
			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				$injector->process($range, $ast);
			}
		}
		
		/**
		 * Resolve all entity names
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function resolveEntityNamespaces(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				$entityName = $range->getEntityName();
				$resolvedEntityName = $this->entityStore->normalizeEntityClass($entityName);
				$range->setEntityName($resolvedEntityName);
			}
		}
	}