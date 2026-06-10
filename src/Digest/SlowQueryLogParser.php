<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Digest;
	
	/**
	 * Parses an ObjectQuel slow query log file produced by SlowQueryLogWriter.
	 *
	 * Expected entry format (blank line terminates each entry):
	 *
	 *   # ObjectQuel: <quel query, single line>
	 *   # Time: <ISO 8601 datetime>
	 *   # Query_time: <float>  Rows_sent: <int>  Sql_count: <int>
	 *   <sql statement 1>;
	 *   <sql statement 2>;
	 *   <blank line>
	 *
	 * Multiple SQL statements are stored as separate lines in the body and
	 * split back into an array on SlowQueryEvent::$sqlStatements.
	 *
	 * Malformed or incomplete entries are silently discarded.
	 * The parser streams the file line by line so memory stays flat
	 * regardless of log file size.
	 */
	class SlowQueryLogParser {
		/**
		 * Parse a slow query log file and yield one SlowQueryEvent per valid entry.
		 *
		 * @param string $filePath Absolute or relative path to the log file.
		 * @return \Generator<int, SlowQueryEvent>
		 * @throws \RuntimeException When the file cannot be opened.
		 */
		public function parse(string $filePath): \Generator {
			$handle = @fopen($filePath, 'r');
			
			if ($handle === false) {
				throw new \RuntimeException(sprintf('Cannot open slow query log: %s', $filePath));
			}
			
			try {
				yield from $this->parseHandle($handle);
			} finally {
				fclose($handle);
			}
		}
		
		/**
		 * Core parsing loop. Reads the file handle line by line and assembles
		 * raw entry buffers, then delegates each buffer to tryBuildEvent().
		 *
		 * @param resource $handle
		 * @return \Generator<int, SlowQueryEvent>
		 */
		private function parseHandle($handle): \Generator {
			// Lines accumulated for the current entry being parsed.
			$buffer = [];
			
			while (($line = fgets($handle)) !== false) {
				$line = rtrim($line, "\r\n");
				
				if ($line === '') {
					// Blank line = entry terminator. Attempt to build an event
					// from whatever we buffered, then reset for the next entry.
					if (!empty($buffer)) {
						$event = $this->tryBuildEvent($buffer);
						
						if ($event !== null) {
							yield $event;
						}
						
						$buffer = [];
					}
				} else {
					$buffer[] = $line;
				}
			}
			
			// Handle a file that does not end with a trailing blank line.
			if (!empty($buffer)) {
				$event = $this->tryBuildEvent($buffer);
				
				if ($event !== null) {
					yield $event;
				}
			}
		}
		
		/**
		 * Attempt to build a SlowQueryEvent from a raw buffer of lines.
		 * Returns null and silently discards the entry if any required field
		 * is missing or unparseable.
		 *
		 * @param string[] $lines
		 */
		private function tryBuildEvent(array $lines): ?SlowQueryEvent {
			$quelQuery = null;
			$time = null;
			$queryTime = null;
			$rowsSent = null;
			$sqlLines = [];
			
			foreach ($lines as $line) {
				if (str_starts_with($line, '# ObjectQuel: ')) {
					$quelQuery = substr($line, strlen('# ObjectQuel: '));
				} elseif (str_starts_with($line, '# Time: ')) {
					$time = $this->parseTime(substr($line, strlen('# Time: ')));
				} elseif (str_starts_with($line, '# Query_time: ')) {
					[$queryTime, $rowsSent] = $this->parseStats(substr($line, strlen('# Query_time: ')));
				} elseif (!str_starts_with($line, '#')) {
					// Any non-header line is part of the SQL body.
					$sqlLines[] = $line;
				}
			}
			
			// All fields are required. Discard partial entries silently.
			if ($quelQuery === null || $time === null || $queryTime === null || $rowsSent === null || empty($sqlLines)) {
				return null;
			}
			
			return new SlowQueryEvent(
				quelQuery: $quelQuery,
				sqlStatements: $sqlLines,
				time: $time,
				queryTime: $queryTime,
				rowsSent: $rowsSent,
			);
		}
		
		/**
		 * Parse an ISO 8601 datetime string into a DateTimeImmutable.
		 * Returns null if the value cannot be parsed.
		 */
		private function parseTime(string $value): ?\DateTimeImmutable {
			$dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, trim($value));
			return $dt !== false ? $dt : null;
		}
		
		/**
		 * Parse the stats line: "1.234567  Rows_sent: 10  Sql_count: 2"
		 * Sql_count is informational only — the actual count comes from the
		 * number of SQL lines in the body, so we do not store it separately.
		 * Returns [queryTime, rowsSent] or [null, null] on failure.
		 *
		 * @return array{0: float|null, 1: int|null}
		 */
		private function parseStats(string $value): array {
			if (!preg_match('/^([\d.]+)\s+Rows_sent:\s*(\d+)/', trim($value), $matches)) {
				return [null, null];
			}
			
			return [(float)$matches[1], (int)$matches[2]];
		}
	}