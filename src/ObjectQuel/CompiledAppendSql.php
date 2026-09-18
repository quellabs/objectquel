<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Represents the compiled SQL for an AST append operation.
	 *
	 * For plain INSERTs and dialect-native upserts, $primarySql contains a
	 * complete statement and $fallbackUpdateSql is null.
	 *
	 * When an upsert's conflict condition does not match a declared constraint,
	 * $fallbackUpdateSql contains an UPDATE to execute first. If it affects
	 * zero rows, $primarySql (a plain INSERT) is executed. Both statements
	 * must run inside a single transaction.
	 *
	 * @see QuelToSQLUpsert
	 * @see AppendExecutor
	 */
	final readonly class CompiledAppendSql {
		
		public string $primarySql;
		public ?string $fallbackUpdateSql;
		
		/**
		 * CompiledAppendSql constructor
		 * @param string $primarySql Primary INSERT or upsert SQL.
		 * @param string|null $fallbackUpdateSql Fallback UPDATE SQL, if required.
		 */
		private function __construct(string $primarySql, ?string $fallbackUpdateSql) {
			$this->primarySql = $primarySql;
			$this->fallbackUpdateSql = $fallbackUpdateSql;
		}
		
		/**
		 * Creates a result containing a single SQL statement.
		 * @param string $sql Complete INSERT or dialect-native upsert statement.
		 * @return self Result without a fallback UPDATE.
		 */
		public static function single(string $sql): self {
			return new self($sql, null);
		}
		
		/**
		 * Creates a result with a fallback UPDATE and primary INSERT.
		 * The UPDATE must be executed first. The INSERT is only executed when
		 * the UPDATE affects zero rows.
		 * @param string $insertSql Primary INSERT SQL statement.
		 * @param string $updateSql Fallback UPDATE SQL statement.
		 * @return self Result containing both SQL statements.
		 */
		public static function withFallbackUpdate(string $insertSql, string $updateSql): self {
			return new self($insertSql, $updateSql);
		}
		
		/**
		 * Checks whether a fallback UPDATE is available.
		 * @return bool True if a fallback UPDATE exists, false otherwise.
		 */
		public function hasFallbackUpdate(): bool {
			return $this->fallbackUpdateSql !== null;
		}
		
		/**
		 * Returns the fallback UPDATE SQL statement.
		 * @return string The fallback UPDATE SQL statement.
		 * @throws \LogicException If no fallback UPDATE is available.
		 */
		public function getFallbackUpdateSqlOrFail(): string {
			if ($this->fallbackUpdateSql === null) {
				throw new \LogicException(
					'CompiledAppendSql has no fallback update SQL — '
					. 'check hasFallbackUpdate() first'
				);
			}
			
			return $this->fallbackUpdateSql;
		}
	}