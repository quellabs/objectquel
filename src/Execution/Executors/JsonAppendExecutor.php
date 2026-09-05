<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Execution\Helpers\ConditionEvaluator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Executes an `append to <range> (...)` statement targeting a JSON-source
	 * range — see objectquel-json-append-plan.md for why this is scoped to
	 * the literal-values form only (no insert-from-select, no `or replace`
	 * on-conflict, no JSONPath-narrowed range; all three are rejected at
	 * parse time in Rules\Append, so none of them ever reach this class).
	 *
	 * Bypasses QuelToSQLAppend/SQL entirely — JSON ranges are never sent to
	 * the database, mirroring JsonQueryExecutor's read-side separation. The
	 * target file must already exist (append never creates it), matching the
	 * DB precedent that `append` never creates the table it targets.
	 *
	 * Every read-decode-mutate-encode-write cycle happens under a sibling
	 * `.lock` file, following the same convention
	 * ProxyGenerator\Generator\FileProxyGenerator uses for concurrent-safe
	 * file writes: lock a `<path>.lock` file, not the target file itself, so
	 * a plain (unlocked) read of the target elsewhere doesn't block on it.
	 */
	class JsonAppendExecutor {

		/**
		 * Compile (in-memory) and execute an `append to <range> (...)`
		 * statement targeting a JSON-source range.
		 * @param AstAppend $statement
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On a missing/invalid target file or a failed write
		 */
		public function execute(AstAppend $statement, array $parameters): QuelResult {
			$range = $statement->getRange();
			assert($range instanceof AstRangeJsonSource);

			$path = $range->getPath();
			$lockFile = $path . '.lock';
			$lockHandle = fopen($lockFile, 'c+');

			if ($lockHandle === false) {
				throw new QuelException("append to '{$path}': could not create lock file '{$lockFile}'", 'append_error');
			}

			try {
				if (!flock($lockHandle, LOCK_EX)) {
					throw new QuelException("append to '{$path}': could not acquire an exclusive lock", 'append_error');
				}

				$rows = $this->loadRows($path);
				$appendedRows = $statement->getRowsOrFail();

				foreach ($appendedRows as $row) {
					$rows[] = $this->evaluateRow($row, $parameters);
				}

				$this->writeRowsAtomically($path, $rows);

				return QuelResult::fromWriteStatement(count($appendedRows), null);
			} finally {
				flock($lockHandle, LOCK_UN);
				fclose($lockHandle);
				@unlink($lockFile);
			}
		}

		/**
		 * Loads and decodes the target JSON file, validating it holds a flat
		 * top-level array of row-objects — the same shape
		 * JsonQueryExecutor::loadAndFilterJsonFile() requires for a range with
		 * no JSONPath expression (the only shape an append target may have,
		 * enforced at parse time in Rules\Append).
		 * @param string $path
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		private function loadRows(string $path): array {
			$contents = @file_get_contents($path);

			if ($contents === false) {
				throw new QuelException("append target JSON file not found: {$path}", 'append_error');
			}

			$decoded = json_decode($contents, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new QuelException("append target '{$path}': error decoding JSON file: " . json_last_error_msg(), 'append_error');
			}

			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new QuelException("append target '{$path}': JSON source did not resolve to an array of rows", 'append_error');
			}

			return $decoded;
		}

		/**
		 * Evaluates one assignment row into a plain PHP assoc array, keyed by
		 * property name. Assignment values (literals, parameters, casts,
		 * arithmetic, etc.) are evaluated the same way JsonQueryExecutor
		 * evaluates a retrieve() projection list — there is no other range in
		 * scope for an append's assignment values, so an empty row/contents
		 * context is correct here.
		 * @param AstAssignment[] $row
		 * @param array<string, mixed> $parameters
		 * @return array<string, mixed>
		 */
		private function evaluateRow(array $row, array $parameters): array {
			$evaluated = [];

			foreach ($row as $assignment) {
				$evaluated[$assignment->getProperty()] = ConditionEvaluator::evaluate($assignment->getValue(), [], [], $parameters);
			}

			return $evaluated;
		}

		/**
		 * Writes the full row set back to disk atomically: encode to a temp
		 * file in the same directory, then rename() it over the original path.
		 * Called only while the caller already holds the `.lock` file's
		 * exclusive lock.
		 * @param string $path
		 * @param list<array<string, mixed>> $rows
		 * @throws QuelException
		 */
		private function writeRowsAtomically(string $path, array $rows): void {
			$encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			if ($encoded === false) {
				throw new QuelException("append target '{$path}': failed to encode JSON: " . json_last_error_msg(), 'append_error');
			}

			$tempPath = $path . '.tmp.' . uniqid('', true);

			if (file_put_contents($tempPath, $encoded) === false) {
				throw new QuelException("append target '{$path}': failed to write temporary file '{$tempPath}'", 'append_error');
			}

			if (!rename($tempPath, $path)) {
				@unlink($tempPath);
				throw new QuelException("append target '{$path}': failed to replace file with updated contents", 'append_error');
			}
		}
	}
