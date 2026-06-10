<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Digest;
	
	/**
	 * Immutable value object representing a single entry in the ObjectQuel slow query log.
	 *
	 * Produced by SlowQueryLogParser. All timing field names match the MySQL slow
	 * query log conventions for consistency.
	 */
	readonly class SlowQueryEvent {
		/**
		 * @param string $quelQuery The original ObjectQuel query string.
		 * @param string[] $sqlStatements All SQL statements generated for this query, in execution order.
		 * @param \DateTimeImmutable $time Wall-clock time at which the query was executed.
		 * @param float $queryTime Total execution time in seconds, including hydration.
		 * @param int $rowsSent Number of rows returned by the query.
		 */
		public function __construct(
			public string $quelQuery,
			public array $sqlStatements,
			public \DateTimeImmutable $time,
			public float $queryTime,
			public int $rowsSent,
		) {
		}
	}