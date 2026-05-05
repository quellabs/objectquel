<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Main orchestrator that coordinates all query optimization strategies.
	 * Acts as a facade that delegates to specialized optimizers.
	 */
	class QueryTransformer {
		
		// Core optimization strategies - each handles a specific type of optimization
		private Optimizers\AnyOptimizer $anyOptimizer;                       // Optimize ANY statements
		private Optimizers\RangeOptimizer $rangeOptimizer;                   // Optimizes range queries and filtering
		private Optimizers\JoinOptimizer $joinOptimizer;                     // Handles JOIN operations and elimination
		private Optimizers\AggregateOptimizer $aggregateOptimizer;           // Optimizes aggregate functions (COUNT, SUM, etc.)
		private Optimizers\ExistsOptimizer $existsOptimizer;                 // Converts EXISTS subqueries to more efficient forms
		private Optimizers\JoinConditionFieldInjector $JoinConditionFieldInjector; // Optimizes value references and constants
		
		/**
		 * Initialize all optimizer components with shared EntityManager dependency.
		 * @param EntityManager $entityManager Provides metadata about entities/tables for optimization decisions
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			// Initialize optimizers that need entity metadata
			$this->anyOptimizer = new Optimizers\AnyOptimizer($entityManager);
			$this->rangeOptimizer = new Optimizers\RangeOptimizer($entityManager);
			$this->joinOptimizer = new Optimizers\JoinOptimizer($entityManager);
			$this->aggregateOptimizer = new Optimizers\AggregateOptimizer($platform);
			
			// Initialize stateless optimizers that work on AST structure alone
			$this->existsOptimizer = new Optimizers\ExistsOptimizer();
			$this->JoinConditionFieldInjector = new Optimizers\JoinConditionFieldInjector();
		}
		
		/**
		 * Optimize the query using all available optimization strategies.
		 * Applies optimizations recursively to nested queries first (depth-first),
		 * then optimizes the outer query.
		 * @param AstRetrieve $ast The query AST to optimize in-place
		 */
		public function transform(AstRetrieve $ast): void {
			// Phase 1: Basic range and relationship optimizations
			// Apply filtering early to reduce dataset size for subsequent operations
			$this->rangeOptimizer->optimize($ast);
			
			// Phase 2: Remove left joins that are not referenced in the query
			$this->rangeOptimizer->removeUnusedLeftJoinRanges($ast);
			$this->rangeOptimizer->removeUnusedTemporaryRanges($ast);
			
			// Phase 3: Optimize joins
			$this->joinOptimizer->optimize($ast);
			
			// Phase 4: Subquery and aggregate optimizations
			// Convert EXISTS to JOINs where beneficial, then optimize aggregates
			// These may create new optimization opportunities for previous phases
			$this->existsOptimizer->optimize($ast);
			$this->anyOptimizer->optimize($ast);
			$this->aggregateOptimizer->optimize($ast);
			
			// Phase 5: Final cleanup
			// Optimize constant values and references last when structure is stable
			$this->joinOptimizer->optimize($ast);
			$this->rangeOptimizer->removeUnusedLeftJoinRanges($ast, false);
			$this->JoinConditionFieldInjector->optimize($ast);
		}
	}