<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Flow\JSONPath\JSONPathException;
	use Quellabs\ObjectQuel\Execution\ConditionEvaluator;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Handles JSON file query execution
	 */
	class JsonQueryExecutor {
		private ConditionEvaluator $conditionEvaluator;
		
		public function __construct(ConditionEvaluator $conditionEvaluator) {
			$this->conditionEvaluator = $conditionEvaluator;
		}
		
		/**
		 * Execute a JSON query and returns the result
		 * @param ExecutionStage $stage
		 * @param array $initialParams
		 * @return array
		 * @throws QuelException
		 */
		public function execute(ExecutionStage $stage, array $initialParams = []): array {
			// Load the JSON file and perform initial filtering
			$contents = $this->loadAndFilterJsonFile($stage->getRange());
			
			// Use the conditions to further filter the file
			$result = [];
			
			foreach ($contents as $row) {
				if ($stage->getQuery()->getConditions() === null ||
					$this->conditionEvaluator->evaluate($stage->getQuery()->getConditions(), $row, $initialParams)) {
					$result[] = $row;
				}
			}
			
			return $result;
		}
		
		/**
		 * Load and filter a JSON file from a JSON source
		 * @param AstRangeJsonSource $source
		 * @return array
		 * @throws QuelException
		 */
		private function loadAndFilterJsonFile(AstRangeJsonSource $source): array {
			// Load the JSON file
			$contents = file_get_contents($source->getPath());
			
			if ($contents === false) {
				throw new QuelException("JSON file {$source->getName()} not found");
			}
			
			// Decode the JSON file
			$decoded = json_decode($contents, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new QuelException("Error decoding JSON file {$source->getName()}: " . json_last_error_msg());
			}
			
			// If a JSONPath was given, use it to filter the output
			if (!empty($source->getExpression())) {
				try {
					$decoded = (new \Flow\JSONPath\JSONPath($decoded))->find($source->getExpression())->getData();
				} catch (JSONPathException $e) {
					throw new QuelException($e->getMessage(), $e->getCode(), $e);
				}
			}
			
			// Prefix all items with the range alias
			$result = [];
			$alias = $source->getName();
			
			foreach ($decoded as $row) {
				$line = [];
				
				foreach ($row as $key => $value) {
					$line["{$alias}.{$key}"] = $value;
				}
				
				$result[] = $line;
			}
			
			return $result;
		}
	}