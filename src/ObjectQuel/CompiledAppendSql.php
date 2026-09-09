<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

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
	final class CompiledAppendSql {

		private function __construct(
			public readonly string $primarySql,
			public readonly ?string $fallbackUpdateSql,
		) {
		}

		public static function single(string $sql): self {
			return new self($sql, null);
		}

		public static function withFallbackUpdate(string $insertSql, string $updateSql): self {
			return new self($insertSql, $updateSql);
		}

		public function hasFallbackUpdate(): bool {
			return $this->fallbackUpdateSql !== null;
		}

		public function getFallbackUpdateSqlOrFail(): string {
			if ($this->fallbackUpdateSql === null) {
				throw new \LogicException('CompiledAppendSql has no fallback update SQL — check hasFallbackUpdate() first');
			}

			return $this->fallbackUpdateSql;
		}
	}
