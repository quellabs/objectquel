<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\ConflictTargetResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;

	/**
	 * Compiles upsert's on-conflict extension of `append` (see
	 * objectquel-upsert-plan.md) to dialect-correct SQL. Not a sibling
	 * compiler for its own AST node the way QuelToSQLReplace/QuelToSQLDelete
	 * are — there is no `AstUpsert`. Upsert is `AstAppend` with an optional
	 * `?AstReplace $onConflict` slot (the existing `replace` grammar, minus
	 * its own target range), so this class exists purely to keep that
	 * dialect-branching logic out of QuelToSQLAppend, which QuelToSQLAppend
	 * calls into only when `$onConflict` is non-null, handing it the base
	 * INSERT SQL and the already-compiled rows it needs to build on.
	 *
	 * `or replace`'s assignment list is itself optional
	 * (`AstReplace::getAssignments() === []`): `append to u (...) or replace
	 * where <cond>`, with no parenthesized list at all, means "on conflict,
	 * overwrite every appended column with the row that would have been
	 * inserted" — the common upsert case, and QUEL reusing its own
	 * `append`/`replace`/`or` verbs rather than inventing `ON CONFLICT`-
	 * shaped clause words for it. Only when the caller needs the
	 * conflict-time update to differ from the insert (e.g. incrementing a
	 * counter instead of overwriting it) do they write the list out
	 * explicitly, same as a standalone `replace`.
	 *
	 * Dialect branching is via PlatformCapabilitiesInterface::
	 * getDatabaseType() — the same "build engine-specific SQL text from
	 * scratch" approach QuelToSQLCreate/QuelToSQLDestroy already use, not a
	 * bespoke capability method for a one-off shape:
	 *   - Postgres/SQLite: `INSERT ... ON CONFLICT (cols) DO UPDATE SET ...`,
	 *     the default SET referencing `EXCLUDED.col` (the row that would
	 *     have been inserted).
	 *   - MySQL/MariaDB:   `INSERT ... ON DUPLICATE KEY UPDATE ...` — fires
	 *     on *any* unique-key collision on the table, not only the named
	 *     conflict columns; a real MySQL syntax gap, not something this
	 *     compiler can paper over. The default SET references `VALUES(col)`.
	 *   - SQL Server:      `MERGE ... WHEN MATCHED THEN UPDATE ... WHEN NOT
	 *     MATCHED THEN INSERT ...`, the default SET referencing the USING
	 *     source's own `source.col` (already computed there for the INSERT
	 *     branch).
	 */
	class QuelToSQLUpsert {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLReplace $replaceCompiler;

		/**
		 * QuelToSQLUpsert constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 * @param QuelToSQLReplace $replaceCompiler Reused (not reconstructed) for
		 *        an explicit on-conflict UPDATE SET clause, so it's built with the
		 *        exact same property-exists/type/@Orm\Version-bump rules a
		 *        standalone `replace` uses — see QuelToSQLReplace::buildSetClause().
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, QuelToSQLReplace $replaceCompiler) {
			$this->entityStore = $entityStore;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
			$this->replaceCompiler = $replaceCompiler;
		}

		/**
		 * Resolves and validates the on-conflict clause, then compiles the
		 * dialect-appropriate insert-or-update statement.
		 * @param string $insertSql The already-compiled base `INSERT INTO
		 *        table (cols) VALUES (...), (...)` — reused as-is for the
		 *        Postgres/SQLite/MySQL branches (SQL Server's MERGE has no
		 *        INSERT of its own, so it doesn't use this).
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties Row property order — determines column order
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows Per-row compiled
		 *        values keyed by property, as produced by QuelToSQLAppend
		 * @param AstReplace $onConflict
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException
		 */
		public function convertToSQL(
			string $insertSql,
			EntityMetadataRecord $metadata,
			array $properties,
			array $columnNames,
			array $compiledRows,
			AstReplace $onConflict,
			array &$parameters
		): string {
			// The on-conflict clause's own WHERE/assignment identifiers need a
			// resolved type/range before ConflictTargetResolver or
			// buildSetClause can read them.
			WriteVerbIdentifierResolver::resolve($onConflict, $this->entityStore);

			$conflictProperties = ConflictTargetResolver::resolve($onConflict->getConditions(), $metadata);
			$this->assertConflictPropertiesSuppliedByRow($conflictProperties, $properties, $metadata);

			$conflictColumns = array_map(fn(string $property) => $metadata->getColumnName($property), $conflictProperties);
			$explicitAssignments = $onConflict->getAssignments();
			$dialect = $this->platform->getDatabaseType();

			if (in_array($dialect, ['pgsql', 'sqlite'], true)) {
				$setClauseParts = $explicitAssignments !== []
					? $this->replaceCompiler->buildSetClause($explicitAssignments, $metadata, $parameters)
					: $this->buildReferencedSetClause($columnNames, 'EXCLUDED', asFunction: false);

				return sprintf(
					'%s ON CONFLICT (%s) DO UPDATE SET %s',
					$insertSql,
					$this->quoteIdentifierList($conflictColumns),
					implode(', ', $setClauseParts)
				);
			}

			if (in_array($dialect, ['mysql', 'mariadb'], true)) {
				$setClauseParts = $explicitAssignments !== []
					? $this->replaceCompiler->buildSetClause($explicitAssignments, $metadata, $parameters)
					: $this->buildReferencedSetClause($columnNames, 'VALUES', asFunction: true);

				return sprintf('%s ON DUPLICATE KEY UPDATE %s', $insertSql, implode(', ', $setClauseParts));
			}

			// sqlsrv — no ON CONFLICT/ON DUPLICATE KEY UPDATE equivalent at all.
			return $this->compileMerge($metadata, $properties, $columnNames, $compiledRows, $conflictColumns, $explicitAssignments, $parameters);
		}

		/**
		 * Compiles the SQL Server `MERGE` form. The USING source is a VALUES
		 * row constructor exposing every appended column (not just the
		 * conflict columns) under the same compiled expressions the plain
		 * INSERT uses, so:
		 *   - the ON clause can compare target.col = source.col per conflict column
		 *   - WHEN NOT MATCHED's INSERT can reference source.col for every
		 *     column, instead of re-embedding per-row literals a second time
		 *   - WHEN MATCHED's UPDATE SET can reference source.col too, for the
		 *     "no explicit replace list" default case
		 * When an explicit `or replace (...)` list is given, WHEN MATCHED's
		 * UPDATE SET uses those independently compiled assignment values
		 * instead — never source.*, since those expressions may differ from
		 * what was inserted.
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows
		 * @param string[] $conflictColumns
		 * @param \Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment[] $explicitAssignments Empty means "default to the inserted row"
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileMerge(
			EntityMetadataRecord $metadata,
			array $properties,
			array $columnNames,
			array $compiledRows,
			array $conflictColumns,
			array $explicitAssignments,
			array &$parameters
		): string {
			$targetAlias = $this->identifierQuoter->quoteIdentifier('__upsert_target');
			$sourceAlias = $this->identifierQuoter->quoteIdentifier('__upsert_source');
			$quotedColumnList = $this->quoteIdentifierList($columnNames);

			$sourceRows = array_map(
				fn(array $compiledRow) => '(' . implode(', ', array_map(fn(string $property) => $compiledRow[$property], $properties)) . ')',
				$compiledRows
			);

			$onClauseParts = array_map(
				fn(string $column) => sprintf(
					'%s.%s = %s.%s',
					$targetAlias,
					$this->identifierQuoter->quoteIdentifier($column),
					$sourceAlias,
					$this->identifierQuoter->quoteIdentifier($column)
				),
				$conflictColumns
			);

			$insertValueRefs = array_map(
				fn(string $column) => $sourceAlias . '.' . $this->identifierQuoter->quoteIdentifier($column),
				$columnNames
			);

			$setClauseParts = $explicitAssignments !== []
				? $this->replaceCompiler->buildSetClause($explicitAssignments, $metadata, $parameters)
				: $this->buildReferencedSetClause($columnNames, $sourceAlias, asFunction: false);

			return sprintf(
				'MERGE INTO %s AS %s USING (VALUES %s) AS %s (%s) ON %s WHEN MATCHED THEN UPDATE SET %s WHEN NOT MATCHED THEN INSERT (%s) VALUES (%s);',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$targetAlias,
				implode(', ', $sourceRows),
				$sourceAlias,
				$quotedColumnList,
				implode(' AND ', $onClauseParts),
				implode(', ', $setClauseParts),
				$quotedColumnList,
				implode(', ', $insertValueRefs)
			);
		}

		/**
		 * Builds the default "overwrite with the row that would have been
		 * inserted" SET clause fragments — used whenever `or replace` has no
		 * explicit assignment list. Every appended column is included
		 * unconditionally, conflict columns too (re-setting a column to its
		 * own value is a harmless no-op, and excluding it would be an extra
		 * rule to state for no real benefit).
		 * @param string[] $columnNames
		 * @param string $reference Either a pseudo-table name to qualify
		 *        each column with (`EXCLUDED`, or a USING source alias for
		 *        MERGE), or — when $asFunction is true — a function name
		 *        each column is passed to (MySQL's `VALUES(col)`).
		 * @param bool $asFunction
		 * @return string[]
		 */
		private function buildReferencedSetClause(array $columnNames, string $reference, bool $asFunction): array {
			return array_map(
				function (string $column) use ($reference, $asFunction) {
					$quotedColumn = $this->identifierQuoter->quoteIdentifier($column);

					return $asFunction
						? "{$quotedColumn} = {$reference}({$quotedColumn})"
						: "{$quotedColumn} = {$reference}.{$quotedColumn}";
				},
				$columnNames
			);
		}

		/**
		 * Every conflict-target property must also be part of the append's
		 * own row — otherwise there's nothing to compare against for
		 * detecting the conflict on that row. (All rows of a multi-row
		 * append share the same property set — enforced at parse time by
		 * Rules\Append — so checking the first row's set covers every row.)
		 * @param string[] $conflictProperties
		 * @param string[] $rowProperties
		 * @param EntityMetadataRecord $metadata
		 * @return void
		 * @throws SemanticException
		 */
		private function assertConflictPropertiesSuppliedByRow(array $conflictProperties, array $rowProperties, EntityMetadataRecord $metadata): void {
			$missing = array_diff($conflictProperties, $rowProperties);

			if (!empty($missing)) {
				throw new SemanticException(sprintf(
					"append ... or replace's conflict target (%s) must also be part of the append's own column list on '%s' — missing: %s",
					implode(', ', $conflictProperties),
					$metadata->className,
					implode(', ', $missing)
				));
			}
		}

		/**
		 * @param string[] $identifiers Unquoted identifiers
		 * @return string Comma-separated, quoted identifier list
		 */
		private function quoteIdentifierList(array $identifiers): string {
			return implode(', ', array_map(
				fn(string $identifier) => $this->identifierQuoter->quoteIdentifier($identifier),
				$identifiers
			));
		}
	}
