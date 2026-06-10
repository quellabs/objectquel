<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Digest;
	
	/**
	 * Writes ObjectQuel query execution data to a slow query log file.
	 *
	 * The log format mirrors the MySQL slow query log so that entries are
	 * immediately recognisable. One QUEL query = one log entry, even when
	 * the planner generates multiple SQL statements for it.
	 *
	 *   # ObjectQuel: <original quel query, single line>
	 *   # Time: 2024-01-15T10:23:45.123456+00:00
	 *   # Query_time: 1.234567  Rows_sent: 10  Sql_count: 2
	 *   SELECT u.id, u.name FROM users u WHERE u.active = 1;
	 *   INSERT INTO _tmp_1 SELECT ...;
	 *
	 * A blank line terminates each entry. The writer is stateless beyond the
	 * file path and threshold. Call write() from EntityManager::executeQuery
	 * after execution completes.
	 */
	class SlowQueryLogWriter {
		/**
		 * @param string $logPath Absolute path to the log file.
		 * @param float $threshold Minimum query time in seconds to log.
		 *                           0.0 means log every query.
		 */
		public function __construct(
			private readonly string $logPath,
			private readonly float $threshold = 0.0,
		) {
		}
		
		/**
		 * Write a query execution entry to the log if it meets the threshold.
		 *
		 * @param string $quelQuery The original ObjectQuel query string.
		 * @param string[] $sqlList All SQL statements generated for this query.
		 * @param float $queryTime Total execution time in seconds.
		 * @param int $rowsSent Number of rows returned by the query.
		 */
		public function write(string $quelQuery, array $sqlList, float $queryTime, int $rowsSent): void {
			// Skip if below the configured threshold
			if ($queryTime < $this->threshold) {
				return;
			}
			
			// Normalise each SQL statement: collapse surrounding whitespace and
			// ensure a single trailing semicolon for unambiguous parsing.
			$normalisedStatements = array_map(
				static fn(string $sql): string => rtrim(trim($sql), ';') . ';',
				array_filter($sqlList, static fn(string $sql): bool => trim($sql) !== ''),
			);
			
			$time = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
			
			$entry = sprintf(
				"# ObjectQuel: %s\n# Time: %s\n# Query_time: %.6f  Rows_sent: %d  Sql_count: %d\n%s\n\n",
				$this->normaliseQuelQuery($quelQuery),
				$time,
				$queryTime,
				$rowsSent,
				count($normalisedStatements),
				implode("\n", $normalisedStatements),
			);
			
			// FILE_APPEND | LOCK_EX: safe for concurrent PHP processes writing
			// to the same log file.
			file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
		}
		
		/**
		 * Collapse the QUEL query to a single line so the log entry stays
		 * parseable. Newlines inside the query string would break the
		 * line-by-line parser.
		 */
		private function normaliseQuelQuery(string $quelQuery): string {
			return preg_replace('/\s+/', ' ', trim($quelQuery)) ?? trim($quelQuery);
		}
	}