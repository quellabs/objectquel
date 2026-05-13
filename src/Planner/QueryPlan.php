<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	/**
	 * The combined result of a dry-run query explanation.
	 *
	 * Holds the planner's decisions (why joins were promoted, which search
	 * strategy was chosen, etc.) alongside the SQL that would be executed,
	 * without having touched the database.
	 */
	readonly class QueryPlan {
		
		/**
		 * @param PlanNote[] $notes Planning decisions in pipeline order
		 * @param list<string> $sql  SQL statements that would be executed, one per stage
		 */
		public function __construct(
			private array $notes,
			private array $sql,
		) {}
		
		/**
		 * Returns the planning decisions recorded during optimization and plan building.
		 * @return PlanNote[]
		 */
		public function getNotes(): array {
			return $this->notes;
		}
		
		/**
		 * Returns the SQL statements that would be sent to the database, one per stage.
		 * @return list<string>
		 */
		public function getSql(): array {
			return $this->sql;
		}
	}