<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	
	/**
	 * A dry-run executor that captures generated SQL without executing it.
	 * Used by the demo endpoint to show visitors what SQL ObjectQuel produces
	 * for a given query, including all stages of a decomposed execution plan.
	 */
	class DryRunDatabaseQueryExecutor extends DatabaseQueryExecutor {
		
		/**
		 * SQL statements captured across all executed stages
		 * @var list<string>
		 */
		private array $capturedSql = [];
		
		/** @var QueryOptimizer Optimizing code */
		private QueryOptimizer $queryOptimizer;
		
		/**
		 * DryRunDatabaseQueryExecutor
		 * @param EntityManager $entityManager
		 * @param PlatformCapabilities $capabilities
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilities $capabilities) {
			parent::__construct($entityManager, $capabilities);
			$this->queryOptimizer = new QueryOptimizer($this->entityManager, $this->capabilities);
		}
		
		/**
		 * Optimizes and transforms the query, captures the generated SQL,
		 * and returns an empty result set without touching the database.
		 * @param ExecutionStageInterface $stage
		 * @param array<string, mixed> $initialParams
		 * @return list<array<string, mixed>> Always returns an empty array
		 */
		public function execute(ExecutionStageInterface $stage, array $initialParams = []): array {
			$this->queryOptimizer->optimize($stage->getQuery());
			$this->queryTransformer->transform($stage->getQuery(), $initialParams);
			$this->capturedSql[] = $this->convertToSQL($stage->getQuery(), $initialParams);
			return [];
		}
		
		/**
		 * Returns all SQL statements captured during execution, one per decomposed stage.
		 * @return list<string>
		 */
		public function getCapturedSql(): array {
			return $this->capturedSql;
		}
	}