<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	/**
	 * Contains pre-analyzed information about how tables are used in the query.
	 */
	class QueryAnalysisResult {
		
		/** @var array<string, TableUsageInfo> */
		private array $tableUsageMap;
		
		/**
		 * QueryAnalysisResult constructor
		 * @param array<string, TableUsageInfo> $tableUsageMap
		 */
		public function __construct(array $tableUsageMap) {
			$this->tableUsageMap = $tableUsageMap;
		}
		
		/**
		 * Returns table usage info for the given table name
		 * @param string $tableName
		 * @return TableUsageInfo
		 */
		public function getTableUsage(string $tableName): TableUsageInfo {
			return $this->tableUsageMap[$tableName] ?? new TableUsageInfo();
		}
	}