<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;

	/**
	 * Result of compiling an AstAppend to SQL. In the common case (a plain
	 * INSERT, or — when an upsert's conflict target matches a declared
	 * unique/primary-key constraint — the dialect-native
	 * INSERT...ON CONFLICT/ON DUPLICATE KEY/MERGE statement) this is a single
	 * complete statement: $primarySql, $fallbackUpdateSql null.
	 *
	 * When an upsert's WHERE doesn't match a declared constraint, there's no
	 * dialect-native atomic form to compile to (see QuelToSQLUpsert's
	 * docblock) — $fallbackUpdateSql holds an ordinary `UPDATE ... WHERE
	 * <cond>` to run first; only if it affects 0 rows does $primarySql (a
	 * plain INSERT) run. AppendExecutor runs both inside one transaction.
	 */
	final readonly class CompiledAppendSql {

		public string $primarySql;
		public ?string $fallbackUpdateSql;

		/**
		 * Creates a compiled SQL result with its primary and optional fallback statements.
		 * @param string $primarySql
		 * @param string|null $fallbackUpdateSql
		 * @return void
		 */
		private function __construct(string $primarySql, ?string $fallbackUpdateSql) {
			$this->primarySql = $primarySql;
			$this->fallbackUpdateSql = $fallbackUpdateSql;
		}

		/**
		 * Creates a result containing one complete SQL statement.
		 * @param string $sql
		 * @return self
		 */
		public static function single(string $sql): self {
			return new self($sql, null);
		}

		/**
		 * Creates a result that updates first and inserts if no row matches.
		 * @param string $insertSql
		 * @param string $updateSql
		 * @return self
		 */
		public static function withFallbackUpdate(string $insertSql, string $updateSql): self {
			return new self($insertSql, $updateSql);
		}

		/**
		 * Reports whether a fallback update is present.
		 * @return bool
		 */
		public function hasFallbackUpdate(): bool {
			return $this->fallbackUpdateSql !== null;
		}

		/**
		 * Returns the fallback update SQL or throws when none is present.
		 * @return string
		 * @throws \LogicException When this result has no fallback update
		 */
		public function getFallbackUpdateSqlOrFail(): string {
			if ($this->fallbackUpdateSql === null) {
				throw new \LogicException('CompiledAppendSql has no fallback update SQL — check hasFallbackUpdate() first');
			}

			return $this->fallbackUpdateSql;
		}
	}
