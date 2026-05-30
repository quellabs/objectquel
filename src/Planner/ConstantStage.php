<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Represents a query that contains only constant expressions in its projection
	 * and declares no ranges. Because there are no data sources to query, no SQL is
	 * generated and no in-memory join is performed: the executor evaluates every
	 * projection via ConditionEvaluator against an empty row and returns a single
	 * synthetic result row.
	 *
	 * Example:
	 *   retrieve(1 + 1, "hello", :param)
	 *
	 * A ConstantStage is added to the ExecutionPlan by ExecutionPlanBuilder when
	 * AstRetrieve::getRanges() is empty. It implements ExecutionStageInterface so
	 * it can be held in the plan's stage list and be recognised by PlanExecutor.
	 *
	 * The getQuery() and getRange() methods are required by the interface but have
	 * no meaningful content for this stage type. getQuery() throws because nothing
	 * in the pipeline should attempt to execute a query AST for a constant stage;
	 * getRange() returns null because there is no data source.
	 */
	class ConstantStage implements ExecutionStageInterface {
		
		/**
		 * Unique identifier for this stage within the execution plan
		 * @var string
		 */
		private string $name;
		
		/**
		 * The projection list from the original AstRetrieve. Each AstAlias wraps
		 * one expression (AstNumber, AstString, arithmetic tree, etc.) and carries
		 * the alias name that becomes the key in the result row.
		 * @var AstAlias[]
		 */
		private array $projections;
		
		/**
		 * Query parameters forwarded from the original executeQuery() call.
		 * These are needed so AstParameter nodes inside projections resolve correctly.
		 * @var array<string, mixed>
		 */
		private array $staticParams;
		
		/**
		 * @param string $name Unique stage name
		 * @param AstAlias[] $projections Projection list from AstRetrieve::getValues()
		 * @param array<string, mixed> $staticParams Runtime query parameters
		 */
		public function __construct(string $name, array $projections, array $staticParams = []) {
			$this->name = $name;
			$this->projections = $projections;
			$this->staticParams = $staticParams;
		}
		
		/**
		 * Returns the unique name of this stage
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Returns the projection list to evaluate.
		 * @return AstAlias[]
		 */
		public function getProjections(): array {
			return $this->projections;
		}
		
		/**
		 * Returns the static parameters to use when evaluating projections.
		 * @return array<string, mixed>
		 */
		public function getStaticParams(): array {
			return $this->staticParams;
		}
		
		/**
		 * Not applicable for a constant stage — there is no query AST to execute.
		 * This method exists only to satisfy ExecutionStageInterface; calling it
		 * indicates a programming error in the executor.
		 * @throws \LogicException Always
		 */
		public function getQuery(): AstRetrieve {
			throw new \LogicException('ConstantStage has no query AST — evaluate projections directly via getProjections()');
		}
		
		/**
		 * Returns null — a constant stage has no data source range.
		 * @return AstRange|null
		 */
		public function getRange(): ?AstRange {
			return null;
		}
	}