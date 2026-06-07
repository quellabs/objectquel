<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Flow\JSONPath\JSONPath;
	use Flow\JSONPath\JSONPathException;
	use Quellabs\ObjectQuel\Execution\Helpers\ConditionEvaluator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;
	
	/**
	 * Handles JSON file query execution
	 */
	class JsonQueryExecutor {
		
		/**
		 * Execute a JSON query and returns the result
		 * @param ExecutionStageInterface $stage
		 * @param array<string, mixed> $initialParams
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		public function execute(ExecutionStageInterface $stage, array $initialParams = []): array {
			// Fetch the range
			$jsonRange = $stage->getRange();
			
			// $jsonRange is guaranteed to be AstRangeJsonSource, but PhpStan does not know that.
			// That's why this check was added. To satisfy PhpStan's static analysis.
			assert($jsonRange instanceof AstRangeJsonSource);
			
			// Load the JSON file and perform initial filtering
			$contents = $this->loadAndFilterJsonFile($jsonRange);
			
			// Retrieve the WHERE conditions and the retrieve() field list
			$conditions = $stage->getQuery()->getConditions();
			$values = $stage->getQuery()->getValues();
			
			// Evaluate all rows
			$result = [];
			
			foreach ($contents as $row) {
				// Skip rows that don't satisfy the WHERE clause
				if ($conditions !== null && !ConditionEvaluator::evaluate($conditions, $contents, $row, $initialParams)) {
					continue;
				}
				
				// Project the retrieve() field list onto the row
				$projectedRow = [];
				
				foreach ($values as $alias) {
					$projectedRow[$alias->getName()] = ConditionEvaluator::evaluate(
						$alias->getExpression(),
						$contents,
						$row,
						$initialParams
					);
				}
				
				$result[] = $projectedRow;
			}
			
			return $result;
		}
		
		/**
		 * Load and filter a JSON file from a JSON source
		 * @param AstRangeJsonSource $source
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		private function loadAndFilterJsonFile(AstRangeJsonSource $source): array {
			// Load the JSON file, suppressing the PHP warning — the false return value
			// is the signal we act on; the OS error message is captured below instead.
			$contents = @file_get_contents($source->getPath());
			
			if ($contents === false) {
				throw new QuelException("JSON file not found: {$source->getPath()}");
			}
			
			// Decode the JSON file
			$decoded = json_decode($contents, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new QuelException("Error decoding JSON file {$source->getName()}: " . json_last_error_msg());
			}
			
			// If a JSONPath was given, use it to filter the output.
			// JSONPath::find()->getData() always returns an array of matches.
			// For a path like $.rows, this is [<the rows array>] — one match wrapping
			// the actual row list. We want the matched value itself, not the wrapper.
			if (!empty($source->getExpression())) {
				try {
					$matches = (new JSONPath($decoded))->find($source->getExpression())->getData();
				} catch (JSONPathException $e) {
					throw new QuelException($e->getMessage(), 'jsonpath_error', $e->getCode(), $e);
				}
				
				// If no matches found, return an empty array
				if (!is_array($matches) || empty($matches)) {
					return [];
				}
				
				// Unwrap: take the first match, which should be the target array.
				$decoded = $matches[0];
			}
			
			// Without a JSONPath expression the decoded value is whatever the top-level
			// JSON structure is. If it is not a sequential array of rows there is nothing
			// to iterate — return empty rather than letting foreach blow up on a string
			// or associative object.
			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new QuelException(
					$source->getExpression() !== null
						? "JSONPath expression '{$source->getExpression()}' did not resolve to an array of rows."
						: "JSON source '{$source->getPath()}' did not resolve to an array of rows. Use a JSONPath expression (e.g. '$.rows') to select the correct array."
				);
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