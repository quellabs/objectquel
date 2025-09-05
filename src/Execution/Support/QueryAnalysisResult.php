<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	/**
	 * Contains pre-analyzed information about how tables are used in the query.
	 */
	class QueryAnalysisResult {
		public function __construct(
			private array $tableUsageMap
		) {
		}
		
		public function getTableUsage(string $tableName): TableUsageInfo {
			return $this->tableUsageMap[$tableName] ?? new TableUsageInfo();
		}
	}