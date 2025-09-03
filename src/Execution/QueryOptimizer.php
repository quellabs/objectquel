<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Main orchestrator that coordinates all query optimization strategies.
	 * Acts as a facade that delegates to specialized optimizers.
	 */
	class QueryOptimizer {
		
		// Core optimization strategies - each handles a specific type of optimization
		private Optimizers\AnyOptimizer $anyOptimizer;                       // Optimize ANY statements
		private Optimizers\RangeOptimizer $rangeOptimizer;                   // Optimizes range queries and filtering
		private Optimizers\JoinOptimizer $joinOptimizer;                     // Handles JOIN operations and elimination
		private Optimizers\AggregateOptimizer $aggregateOptimizer;           // Optimizes aggregate functions (COUNT, SUM, etc.)
		private Optimizers\ExistsOptimizer $existsOptimizer;                 // Converts EXISTS subqueries to more efficient forms
		private Optimizers\ValueReferenceOptimizer $valueReferenceOptimizer; // Optimizes value references and constants
		
		/**
		 * Initialize all optimizer components with shared EntityManager dependency.
		 * @param EntityManager $entityManager Provides metadata about entities/tables for optimization decisions
		 */
		public function __construct(EntityManager $entityManager) {
			// Initialize optimizers that need entity metadata
			$this->anyOptimizer = new Optimizers\AnyOptimizer($entityManager);
			$this->rangeOptimizer = new Optimizers\RangeOptimizer($entityManager);
			$this->joinOptimizer = new Optimizers\JoinOptimizer($entityManager);
			$this->aggregateOptimizer = new Optimizers\AggregateOptimizer($entityManager);
			
			// Initialize stateless optimizers that work on AST structure alone
			$this->existsOptimizer = new Optimizers\ExistsOptimizer();
			$this->valueReferenceOptimizer = new Optimizers\ValueReferenceOptimizer();
		}
		
		/**
		 * Optimize the query using all available optimization strategies.
		 * @param AstRetrieve $ast The query AST to optimize in-place
		 */
		public function optimize(AstRetrieve $ast): void {
			// Phase 1: Basic range and relationship optimizations
			// Apply filtering early to reduce dataset size for subsequent operations
			$this->rangeOptimizer->optimize($ast);
			
			// Phase 2: Optimize joins
			$this->joinOptimizer->optimize($ast);
			
			// Phase 3: Subquery and aggregate optimizations
			// Convert EXISTS to JOINs where beneficial, then optimize aggregates
			// These may create new optimization opportunities for previous phases
			$this->existsOptimizer->optimize($ast);
			$this->anyOptimizer->optimize($ast);
			$this->aggregateOptimizer->optimize($ast);
			
			// Phase 4: Final cleanup
			// Remove any LEFT JOINs that became unused after previous optimizations
			// Optimize constant values and references last when structure is stable
			$this->rangeOptimizer->removeUnusedLeftJoinRanges($ast);
			$this->joinOptimizer->optimize($ast);
			$this->valueReferenceOptimizer->optimize($ast);
		}
	}