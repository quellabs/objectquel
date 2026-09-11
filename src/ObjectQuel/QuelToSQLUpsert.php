<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\ConflictTargetResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbParameterNormalizer;
	use Quellabs\ObjectQuel\Serialization\Serializers\SQLSerializer;

	/**
	 * Compiles upsert's on-conflict extension of `append` to dialect-correct
	 * SQL. Not a sibling compiler for its own AST node — there is no
	 * `AstUpsert`; upsert is `AstAppend` with an optional `?AstReplace
	 * $onConflict` slot, so this class exists purely to keep that
	 * dialect-branching logic out of QuelToSQLAppend, which calls into it
	 * only when `$onConflict` is non-null.
	 *
	 * An empty `or replace` assignment list means "overwrite every
	 * appended column with the row that would have been inserted"; the
	 * caller writes the list out explicitly only when the conflict-time
	 * update needs to differ from the insert.
	 *
	 * Dialect branching (via PlatformCapabilitiesInterface::
	 * getDatabaseType()): Postgres/SQLite use `INSERT ... ON CONFLICT ...
	 * DO UPDATE`, MySQL/MariaDB use `INSERT ... ON DUPLICATE KEY UPDATE`
	 * (which fires on any unique-key collision, not just the named
	 * conflict columns), and SQL Server uses `MERGE ... WHEN MATCHED/NOT
	 * MATCHED`.
	 *
	 * When the on-conflict WHERE clause doesn't match a declared unique/
	 * primary-key constraint (see ConflictTargetResolver), there's no
	 * dialect-native atomic form, so convertToSQL() falls back to a
	 * two-statement UPDATE-then-INSERT (see compileNonAtomicFallback()).
	 */
	class QuelToSQLUpsert {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLReplace $replaceCompiler;
		private SQLSerializer $serializer;

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
			// Same reasoning as QuelToSQLReplace's own — an explicit `or
			// replace (...)` list is assignments too, and must denormalize
			// its bound-parameter values identically (see buildSetClauseParts()).
			$this->serializer = new SQLSerializer($entityStore);
		}

		/**
		 * Resolves and validates the on-conflict clause, then compiles the
		 * dialect-appropriate insert-or-update statement.
		 * @param string $insertSql The already-compiled base `INSERT INTO
		 *        table (cols) VALUES (...), (...)` — reused as-is for the
		 *        Postgres/SQLite/MySQL branches (SQL Server's MERGE has no
		 *        INSERT of its own, so it doesn't use this).
		 * @param string $tableName
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties Row property order — determines column order
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows Per-row compiled
		 *        values keyed by property, as produced by QuelToSQLAppend
		 * @param AstReplace $onConflict
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return CompiledAppendSql
		 * @throws SemanticException
		 */
		public function convertToSQL(
			string $insertSql,
			string $tableName,
			EntityMetadataRecord $metadata,
			array $properties,
			array $columnNames,
			array $compiledRows,
			AstReplace $onConflict,
			array &$parameters
		): CompiledAppendSql {
			// The on-conflict clause's own WHERE/assignment identifiers need a
			// resolved type/range before ConflictTargetResolver or
			// buildSetClause can read them.
			WriteVerbIdentifierResolver::resolve($onConflict, $this->entityStore);

			$conflictProperties = ConflictTargetResolver::tryResolve($onConflict->getConditionsOrFail(), $metadata);

			if ($conflictProperties === null) {
				return $this->compileNonAtomicFallback($insertSql, $tableName, $metadata, $onConflict, $properties, $columnNames, $compiledRows, $parameters);
			}

			$targetName = $metadata->className;
			$this->assertConflictPropertiesSuppliedByRow($conflictProperties, $properties, $targetName);

			$conflictColumns = array_map(fn(string $property) => $metadata->getColumnNameOrFail($property), $conflictProperties);

			$explicitAssignments = $onConflict->getAssignments();
			$dialect = $this->platform->getDatabaseType();

			if (in_array($dialect, ['pgsql', 'sqlite'], true)) {
				$setClauseParts = $explicitAssignments !== []
					? $this->buildSetClauseParts($explicitAssignments, $metadata, $parameters)
					: $this->buildReferencedSetClause($columnNames, 'EXCLUDED', asFunction: false, metadata: $metadata, targetLabel: $targetName);

				return CompiledAppendSql::single(sprintf(
					'%s ON CONFLICT (%s) DO UPDATE SET %s',
					$insertSql,
					$this->identifierQuoter->quoteIdentifierList($conflictColumns),
					implode(', ', $setClauseParts)
				));
			}

			if (in_array($dialect, ['mysql', 'mariadb'], true)) {
				$setClauseParts = $explicitAssignments !== []
					? $this->buildSetClauseParts($explicitAssignments, $metadata, $parameters)
					: $this->buildReferencedSetClause($columnNames, 'VALUES', asFunction: true, metadata: $metadata, targetLabel: $targetName);

				return CompiledAppendSql::single(sprintf('%s ON DUPLICATE KEY UPDATE %s', $insertSql, implode(', ', $setClauseParts)));
			}

			// sqlsrv — no ON CONFLICT/ON DUPLICATE KEY UPDATE equivalent at all.
			return CompiledAppendSql::single(
				$this->compileMerge($tableName, $properties, $columnNames, $compiledRows, $conflictColumns, $explicitAssignments, $metadata, $parameters)
			);
		}

		/**
		 * Compiles the WHERE-fallback branch: `or replace`'s WHERE doesn't match
		 * a declared unique/primary-key constraint, so there's no dialect-native
		 * atomic form to compile to (see this class's docblock — ON
		 * CONFLICT/ON DUPLICATE KEY/MERGE are only atomic because the database
		 * enforces the uniqueness itself). Instead: an ordinary `UPDATE ...
		 * WHERE <cond>` — any predicate, any field, the same grammar and set/
		 * where-clause building a standalone `replace` uses — runs first; if it
		 * affects 0 rows, the plain INSERT already compiled by QuelToSQLAppend
		 * ($insertSql) runs instead. AppendExecutor runs both inside one
		 * transaction — see its docblock. If more than one row matches the
		 * UPDATE's WHERE, all of them are updated; that's intentional set-based
		 * behavior, not an error.
		 *
		 * Multi-row is rejected here at compile time: a single shared WHERE
		 * predicate can't identify "this literal row's" match independently per
		 * row the way a real unique constraint lets the database do per-row
		 * conflict detection natively.
		 * @param string $insertSql The already-compiled plain `INSERT INTO table
		 *        (cols) VALUES (...)` — always exactly one row here (multi-row is
		 *        rejected below before this matters).
		 * @param string $tableName
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties Row property order, parallel to $columnNames
		 * @param string[] $columnNames Parallel to $properties — includes the STI
		 *        discriminator's column (itself, not a real property) when present,
		 *        same as QuelToSQLAppend::compileValues() builds it
		 * @param array<int, array<string, string>> $compiledRows Exactly one row here
		 * @param AstReplace $onConflict Already identifier-resolved by the caller
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return CompiledAppendSql
		 * @throws SemanticException
		 */
		private function compileNonAtomicFallback(
			string $insertSql,
			string $tableName,
			EntityMetadataRecord $metadata,
			AstReplace $onConflict,
			array $properties,
			array $columnNames,
			array $compiledRows,
			array &$parameters
		): CompiledAppendSql {
			if (count($compiledRows) > 1) {
				throw new SemanticException(sprintf(
					"append ... or replace's WHERE clause doesn't match a declared unique or primary-key constraint on '%s' — a multi-row append can't fall back to a plain update-or-insert, since a single shared WHERE can't identify each literal row's own match the way a real constraint lets the database do per row. Write single-row append ... or replace statements instead, or back the WHERE with a real unique/primary-key constraint for a multi-row atomic upsert.",
					$metadata->className
				));
			}

			// The on-conflict clause's WHERE/assignment values need denormalizing
			// exactly once before compiling (see WriteVerbParameterNormalizer's
			// docblock) — not done by calling QuelToSQLReplace::convertToSQL()
			// directly, since that would re-run WriteVerbIdentifierResolver a
			// second time on $onConflict (already resolved above).
			$normalizer = new WriteVerbParameterNormalizer($metadata, $this->serializer, $parameters);
			$normalizer->normalizeAssignments($onConflict->getAssignments());
			$onConflict->getConditionsOrFail()->accept($normalizer);

			$assignments = $onConflict->getAssignments();

			$setClauseParts = $assignments !== []
				? $this->replaceCompiler->buildSetClause($assignments, $metadata, $parameters, $onConflict->getRange()->getName())
				: $this->buildDefaultFallbackSetClause($metadata, $properties, $columnNames, $compiledRows[0]);

			$whereSql = (new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform))
				->visitNodeAndReturnSQL($onConflict->getConditionsOrFail());

			$updateSql = sprintf(
				'UPDATE %s as %s SET %s WHERE %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->identifierQuoter->quoteIdentifier($onConflict->getRange()->getName()),
				implode(', ', $setClauseParts),
				$whereSql
			);

			return CompiledAppendSql::withFallbackUpdate($insertSql, $updateSql);
		}

		/**
		 * Default (no explicit `or replace (...)` list) SET clause for the
		 * fallback UPDATE: every appended column except the target's own primary
		 * key, set to the exact value compiled for the INSERT — the plain-UPDATE
		 * equivalent of the atomic path's EXCLUDED/VALUES()/source.* reference
		 * (see buildReferencedSetClause()), which has no meaning outside an
		 * INSERT statement. Always a bare column name: unlike buildSetClause()'s
		 * explicit-list case, this never needs QuelToSQLReplace's per-dialect
		 * alias-qualification (see that class's docblock) — a bare SET target is
		 * valid on every dialect.
		 * @param EntityMetadataRecord $metadata
		 * @param string[] $properties Parallel to $columnNames
		 * @param string[] $columnNames Parallel to $properties
		 * @param array<string, string> $compiledRow property => compiled SQL value
		 * @return string[]
		 * @throws SemanticException When excluding the primary key leaves nothing to update
		 */
		private function buildDefaultFallbackSetClause(EntityMetadataRecord $metadata, array $properties, array $columnNames, array $compiledRow): array {
			$primaryKeyColumn = $this->resolvePrimaryKeyColumn($metadata);
			$setClauseParts = [];

			foreach ($properties as $i => $property) {
				$column = $columnNames[$i];

				if ($column === $primaryKeyColumn) {
					continue;
				}

				$setClauseParts[] = $this->identifierQuoter->quoteIdentifier($column) . ' = ' . $compiledRow[$property];
			}

			if ($setClauseParts === []) {
				throw new SemanticException(
					"append ... or replace's default on-conflict update has nothing to set on '{$metadata->className}': " .
					"every appended column is the primary key, which is always excluded from the default update — write " .
					"an explicit 'or replace (...)' assignment list instead"
				);
			}

			return $setClauseParts;
		}

		/**
		 * Builds an on-conflict UPDATE SET clause.
		 * @param AstAssignment[] $assignments
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string[]
		 * @throws SemanticException
		 */
		private function buildSetClauseParts(array $assignments, EntityMetadataRecord $metadata, array &$parameters): array {
			// Normalize bound-parameter assignment values before compiling
			// them to SQL — see WriteVerbParameterNormalizer's docblock. An
			// explicit `or replace (...)` list is compiled straight to SQL
			// here, never through QuelToSQLReplace::convertToSQL() (that's
			// only a standalone `replace` statement's entry point), so it
			// needs its own call to stay covered.
			(new WriteVerbParameterNormalizer($metadata, $this->serializer, $parameters))->normalizeAssignments($assignments);

			return $this->replaceCompiler->buildSetClause($assignments, $metadata, $parameters);
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
		 * @param string $tableName
		 * @param string[] $properties
		 * @param string[] $columnNames
		 * @param array<int, array<string, string>> $compiledRows
		 * @param string[] $conflictColumns
		 * @param \Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment[] $explicitAssignments Empty means "default to the inserted row"
		 * @param EntityMetadataRecord $metadata
		 * @param array<string, mixed> $parameters
		 * @return string
		 * @throws SemanticException
		 */
		private function compileMerge(
			string $tableName,
			array $properties,
			array $columnNames,
			array $compiledRows,
			array $conflictColumns,
			array $explicitAssignments,
			EntityMetadataRecord $metadata,
			array &$parameters
		): string {
			$targetAlias = $this->identifierQuoter->quoteIdentifier('__upsert_target');
			$sourceAlias = $this->identifierQuoter->quoteIdentifier('__upsert_source');
			$quotedColumnList = $this->identifierQuoter->quoteIdentifierList($columnNames);

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
				? $this->buildSetClauseParts($explicitAssignments, $metadata, $parameters)
				: $this->buildReferencedSetClause($columnNames, $sourceAlias, asFunction: false, metadata: $metadata, targetLabel: $metadata->className);

			return sprintf(
				'MERGE INTO %s AS %s USING (VALUES %s) AS %s (%s) ON %s WHEN MATCHED THEN UPDATE SET %s WHEN NOT MATCHED THEN INSERT (%s) VALUES (%s);',
				$this->identifierQuoter->quoteIdentifier($tableName),
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
		 * own value is a harmless no-op) — except the target entity's own
		 * primary key, which is always excluded: for a generated
		 * (non-identity) primary-key strategy, `$reference.col` is a value
		 * freshly generated for a row that, on conflict, was never actually
		 * inserted, so writing it onto the existing row would silently
		 * reassign that row's identity.
		 * @param string[] $columnNames
		 * @param string $reference Either a pseudo-table name to qualify
		 *        each column with (`EXCLUDED`, or a USING source alias for
		 *        MERGE), or — when $asFunction is true — a function name
		 *        each column is passed to (MySQL's `VALUES(col)`).
		 * @param bool $asFunction
		 * @param EntityMetadataRecord $metadata
		 * @param string $targetLabel Entity class name — for the error message below
		 * @return string[]
		 * @throws SemanticException When excluding the primary key leaves nothing to update — every appended column was the primary key itself
		 */
		private function buildReferencedSetClause(array $columnNames, string $reference, bool $asFunction, EntityMetadataRecord $metadata, string $targetLabel): array {
			$primaryKeyColumn = $this->resolvePrimaryKeyColumn($metadata);
			$updatableColumns = array_values(array_filter($columnNames, fn(string $column) => $column !== $primaryKeyColumn));

			if ($updatableColumns === [] && $primaryKeyColumn !== null) {
				throw new SemanticException(
					"append ... or replace's default on-conflict update has nothing to set on '{$targetLabel}': " .
					"every appended column is the primary key ('{$primaryKeyColumn}'), which is always excluded from " .
					"the default update — write an explicit 'or replace (...)' assignment list instead"
				);
			}

			return array_map(
				function (string $column) use ($reference, $asFunction) {
					$quotedColumn = $this->identifierQuoter->quoteIdentifier($column);

					return $asFunction
						? "{$quotedColumn} = {$reference}({$quotedColumn})"
						: "{$quotedColumn} = {$reference}.{$quotedColumn}";
				},
				$updatableColumns
			);
		}

		/**
		 * Resolves the target entity's primary-key column name, or null when
		 * it has no declared primary key.
		 * @param EntityMetadataRecord $metadata
		 * @return string|null
		 */
		private function resolvePrimaryKeyColumn(EntityMetadataRecord $metadata): ?string {
			if ($metadata->getPrimaryKey() === null) {
				return null;
			}

			return $metadata->getColumnNameOrFail($metadata->getPrimaryKey());
		}

		/**
		 * Every conflict-target property must also be part of the append's
		 * own row — otherwise there's nothing to compare against for
		 * detecting the conflict on that row. (All rows of a multi-row
		 * append share the same property set — enforced at parse time by
		 * Rules\Append — so checking the first row's set covers every row.)
		 * @param string[] $conflictProperties
		 * @param string[] $rowProperties
		 * @param string $label Entity class name
		 * @return void
		 * @throws SemanticException
		 */
		private function assertConflictPropertiesSuppliedByRow(array $conflictProperties, array $rowProperties, string $label): void {
			$missing = array_diff($conflictProperties, $rowProperties);

			if (!empty($missing)) {
				throw new SemanticException(sprintf(
					"append ... or replace's conflict target (%s) must also be part of the append's own column list on '%s' — missing: %s",
					implode(', ', $conflictProperties),
					$label,
					implode(', ', $missing)
				));
			}
		}
	}
