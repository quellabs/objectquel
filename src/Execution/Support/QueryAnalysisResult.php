<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	/**
	 * Contains pre-analyzed information about how tables are used in the query.
	 */
	class QueryAnalysisResult {
		private array $tableUsageMap;
		
		public function __construct(
			array $tableUsageMap
		) {
			$this->tableUsageMap = $tableUsageMap;
		}
		
		public function getTableUsage(string $tableName): TableUsageInfo {
			return $this->tableUsageMap[$tableName] ?? new TableUsageInfo();
		}
	}