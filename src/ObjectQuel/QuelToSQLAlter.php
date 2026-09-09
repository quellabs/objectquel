<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddForeignKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropForeignKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterRenameColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterRetypeColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterSetPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstColumnDefinition;

	/**
	 * Compiles an AstAlterTable statement's column, primary-key, and
	 * foreign-key sub-operations to dialect-correct DDL. Sibling to
	 * QuelToSQLCreate/QuelToSQLCreateIndex/QuelToSQLDestroy — each QUEL
	 * statement kind gets its own compiler here.
	 *
	 * Index sub-operations (`add index`/`drop index`) are NOT compiled by
	 * this class — they're sugar assembled into real AstCreateIndex/
	 * AstDestroyIndex instances and run through the existing
	 * QuelToSQLCreateIndex/QuelToSQLDestroyIndex compilers instead (see
	 * objectquel-index-clause-design.md, "Sugar, not reimplementation"), by
	 * Execution\Executors\AlterTableExecutor, which also owns the
	 * column/PK-before-index execution ordering (see
	 * objectquel-index-clause-design.md, decision 3). Foreign-key
	 * sub-operations (`add foreign key`/`drop foreign key`), by contrast,
	 * ARE compiled directly here — unlike index, three of four dialects
	 * support FK add/drop as one native `ALTER TABLE` statement, so there's
	 * no multi-statement compiler to centralize (see
	 * objectquel-foreign-key-design.md, decision 2).
	 *
	 * One ObjectQuel `alter` statement compiling to several SQL statements
	 * is normal here, same as QuelToSQLCreateIndex/QuelToSQLDestroyIndex —
	 * no attempt is made to fold every sub-operation into one native
	 * multi-clause `ALTER TABLE`, even on engines (MySQL) that could
	 * technically support it; one consistent code path across all four
	 * dialects wins over that micro-optimization (see
	 * objectquel-index-clause-design.md, decision 2).
	 *
	 * `retype`, primary-key changes, and foreign-key changes are all
	 * unsupported on SQLite (no `ALTER COLUMN`/`ADD`/`DROP CONSTRAINT` —
	 * all three require rebuilding the table, which this compiler does not
	 * attempt — see objectquel-alter-table-design.md, "Multi-engine retype
	 * safety", and objectquel-foreign-key-design.md, "Dialect reality").
	 * Adding IDENTITY to an existing column via `retype` is unsupported on
	 * SQL Server (`ALTER COLUMN` cannot add IDENTITY; the column would need
	 * to be recreated). Both cases throw a QuelException loudly rather than
	 * silently emitting wrong or partial SQL — see
	 * objectquel-alter-table-design.md, decision 1's "errors loudly ...
	 * never one that silently drops" precedent.
	 */
	class QuelToSQLAlter {

		private DDLTypeMapper $ddlTypeMapper;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLAlter constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Compiles every column and primary-key sub-operation of an `alter`
		 * statement to SQL, in declaration order. Index sub-operations are
		 * silently skipped here (see class docblock) — the caller compiles
		 * those separately and appends them after this method's result.
		 * @param AstAlterTable $statement
		 * @param string[] $existingPrimaryKeyColumns Resolved by the caller via schema introspection; only consulted when the statement contains a primary-key sub-operation
		 * @param string|null $existingPrimaryKeyConstraintName Resolved by the caller; required only on pgsql/sqlsrv when a primary-key sub-operation must first drop an existing key
		 * @return list<string>
		 * @throws QuelException If a sub-operation isn't representable on the connected engine (see class docblock)
		 */
		public function convertToSQL(AstAlterTable $statement, array $existingPrimaryKeyColumns = [], ?string $existingPrimaryKeyConstraintName = null): array {
			$tableName = $statement->getTableName();
			$statements = [];

			foreach ($statement->getOperations() as $operation) {
				$statements = [
					...$statements,
					...match (true) {
						$operation instanceof AstAlterAddColumn => [$this->compileAddColumn($tableName, $operation)],
						$operation instanceof AstAlterDropColumn => [$this->compileDropColumn($tableName, $operation)],
						$operation instanceof AstAlterRenameColumn => [$this->compileRenameColumn($tableName, $operation)],
						$operation instanceof AstAlterRetypeColumn => $this->compileRetypeColumn($tableName, $operation),
						$operation instanceof AstAlterSetPrimaryKey => $this->compileSetPrimaryKey($tableName, $operation, $existingPrimaryKeyColumns, $existingPrimaryKeyConstraintName),
						$operation instanceof AstAlterDropPrimaryKey => $this->compileDropPrimaryKey($tableName, $existingPrimaryKeyColumns, $existingPrimaryKeyConstraintName),
						$operation instanceof AstAlterAddForeignKey => [$this->compileAddForeignKey($tableName, $operation)],
						$operation instanceof AstAlterDropForeignKey => [$this->compileDropForeignKey($tableName, $operation)],
						// Index sub-operations: compiled by the caller instead.
						default => [],
					},
				];
			}

			return $statements;
		}

		private function quotedTable(string $tableName): string {
			return $this->identifierQuoter->quoteIdentifier($tableName);
		}

		/**
		 * `add attr = type constraints` — a plain new column, rendered with
		 * the same per-dialect column renderer `create` uses.
		 */
		private function compileAddColumn(string $tableName, AstAlterAddColumn $operation): string {
			$column = $operation->getColumn();
			$dialect = $this->platform->getDatabaseType();

			if ($dialect === 'sqlite' && $column->isIdentity()) {
				throw new QuelException(
					"Cannot add identity column '{$column->getName()}' to '{$tableName}': SQLite's autoincrement idiom " .
					"(INTEGER PRIMARY KEY AUTOINCREMENT) can only be declared when a table is created, not added afterwards",
					'alter_unsupported'
				);
			}

			$columnDef = $this->renderColumnDefinition($column);
			$keyword = $dialect === 'sqlsrv' ? 'ADD' : 'ADD COLUMN';

			return sprintf('ALTER TABLE %s %s %s', $this->quotedTable($tableName), $keyword, $columnDef);
		}

		/**
		 * `drop attr` — uniform across every dialect.
		 */
		private function compileDropColumn(string $tableName, AstAlterDropColumn $operation): string {
			return sprintf(
				'ALTER TABLE %s DROP COLUMN %s',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifier($operation->getColumnName())
			);
		}

		/**
		 * `rename oldAttr to newAttr` — every dialect except SQL Server
		 * supports `RENAME COLUMN ... TO ...` directly; SQL Server has no
		 * such ALTER TABLE clause at all and uses `sp_rename` instead.
		 */
		private function compileRenameColumn(string $tableName, AstAlterRenameColumn $operation): string {
			if ($this->platform->getDatabaseType() === 'sqlsrv') {
				$target = $this->identifierQuoter->escapeStringLiteral("{$tableName}.{$operation->getOldName()}");
				$newName = $this->identifierQuoter->escapeStringLiteral($operation->getNewName());

				return "EXEC sp_rename N'{$target}', N'{$newName}', N'COLUMN'";
			}

			return sprintf(
				'ALTER TABLE %s RENAME COLUMN %s TO %s',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifier($operation->getOldName()),
				$this->identifierQuoter->quoteIdentifier($operation->getNewName())
			);
		}

		/**
		 * `retype attr = type constraints` — replaces the column's type and
		 * constraints outright. See class docblock for the SQLite/SQL
		 * Server carve-outs.
		 * @return list<string>
		 */
		private function compileRetypeColumn(string $tableName, AstAlterRetypeColumn $operation): array {
			$column = $operation->getColumn();
			$dialect = $this->platform->getDatabaseType();

			if ($dialect === 'sqlite') {
				throw new QuelException(
					"Cannot retype column '{$column->getName()}' on '{$tableName}': SQLite has no ALTER COLUMN — " .
					"changing a column's type or constraints requires rebuilding the table, which 'alter' does not attempt " .
					"(see objectquel-alter-table-design.md, 'Multi-engine retype safety')",
					'alter_unsupported'
				);
			}

			return match ($dialect) {
				'pgsql' => $this->compilePostgresRetype($tableName, $column),
				'sqlsrv' => [$this->compileSqlServerRetype($tableName, $column)],
				default => [$this->compileMysqlRetype($tableName, $column)],
			};
		}

		/**
		 * MySQL/MariaDB: a single `MODIFY COLUMN` clause covers type, NOT
		 * NULL, and AUTO_INCREMENT all at once.
		 */
		private function compileMysqlRetype(string $tableName, AstColumnDefinition $column): string {
			return sprintf('ALTER TABLE %s MODIFY COLUMN %s', $this->quotedTable($tableName), $this->renderColumnDefinition($column));
		}

		/**
		 * PostgreSQL: type, nullability, and identity are three
		 * independent `ALTER COLUMN` clauses, unlike MySQL's single
		 * `MODIFY COLUMN`. The type change always carries an explicit
		 * `USING` cast — safe even when the cast is a no-op — since a bare
		 * `TYPE` clause fails whenever Postgres can't derive an implicit
		 * cast on its own. Nullability and identity are always emitted
		 * (never conditionally), matching this op's "replaces outright"
		 * semantics: `DROP IDENTITY IF EXISTS` is a safe no-op when the
		 * column was never an identity column.
		 * @return list<string>
		 */
		private function compilePostgresRetype(string $tableName, AstColumnDefinition $column): array {
			$table = $this->quotedTable($tableName);
			$quotedColumn = $this->identifierQuoter->quoteIdentifier($column->getName());
			$type = $this->ddlTypeMapper->getTempTableColumnType($column->toColumnDefinitionArray());

			$statements = [
				sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $table, $quotedColumn, $type, $quotedColumn, $type),
				sprintf('ALTER TABLE %s ALTER COLUMN %s %s NOT NULL', $table, $quotedColumn, $column->isNotNull() ? 'SET' : 'DROP'),
			];

			$statements[] = $column->isIdentity()
				? sprintf('ALTER TABLE %s ALTER COLUMN %s ADD GENERATED BY DEFAULT AS IDENTITY', $table, $quotedColumn)
				: sprintf('ALTER TABLE %s ALTER COLUMN %s DROP IDENTITY IF EXISTS', $table, $quotedColumn);

			return $statements;
		}

		/**
		 * SQL Server: type and nullability are one `ALTER COLUMN` clause,
		 * always with an explicit trailing NULL/NOT NULL (T-SQL otherwise
		 * silently keeps the existing column's nullability instead of
		 * applying this op's "replaces outright" semantics). Adding
		 * IDENTITY isn't representable via `ALTER COLUMN` at all — the
		 * column would need to be recreated — so that combination is
		 * rejected loudly rather than silently ignored.
		 */
		private function compileSqlServerRetype(string $tableName, AstColumnDefinition $column): string {
			if ($column->isIdentity()) {
				throw new QuelException(
					"Cannot retype column '{$column->getName()}' on '{$tableName}' to add 'identity': SQL Server's " .
					"ALTER COLUMN cannot add IDENTITY to an existing column — the column would need to be recreated, " .
					"which 'alter' does not attempt",
					'alter_unsupported'
				);
			}

			$type = $this->ddlTypeMapper->getTempTableColumnType($column->toColumnDefinitionArray());
			$nullability = $column->isNotNull() ? 'NOT NULL' : 'NULL';

			return sprintf(
				'ALTER TABLE %s ALTER COLUMN %s %s %s',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifier($column->getName()),
				$type,
				$nullability
			);
		}

		/**
		 * `primary key (col {, col})` — replaces the full primary key
		 * outright (see objectquel-primary-key-design.md, decision 1): an
		 * existing key is dropped first (dialect-specific form), then the
		 * new one is added. No drop is emitted when the table currently has
		 * no primary key.
		 * @param string[] $existingPrimaryKeyColumns
		 * @return list<string>
		 */
		private function compileSetPrimaryKey(string $tableName, AstAlterSetPrimaryKey $operation, array $existingPrimaryKeyColumns, ?string $existingPrimaryKeyConstraintName): array {
			$this->assertPrimaryKeyChangesSupported($tableName);

			$statements = $existingPrimaryKeyColumns === []
				? []
				: [$this->compileDropExistingPrimaryKey($tableName, $existingPrimaryKeyConstraintName)];

			$statements[] = sprintf(
				'ALTER TABLE %s ADD PRIMARY KEY (%s)',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifierList($operation->getColumns())
			);

			return $statements;
		}

		/**
		 * `drop primary key` — removes the table's primary key entirely.
		 * Pre-checked against $existingPrimaryKeyColumns (rather than left
		 * to the database, unlike most other DDL failures in this codebase)
		 * because pgsql/sqlsrv need a resolved constraint name to build
		 * valid SQL in the first place — there's no statement to emit at
		 * all when none exists, so the check is made uniform across every
		 * dialect rather than only where it's structurally required.
		 * @param string[] $existingPrimaryKeyColumns
		 * @return list<string>
		 */
		private function compileDropPrimaryKey(string $tableName, array $existingPrimaryKeyColumns, ?string $existingPrimaryKeyConstraintName): array {
			$this->assertPrimaryKeyChangesSupported($tableName);

			if ($existingPrimaryKeyColumns === []) {
				throw new QuelException("Cannot drop primary key on '{$tableName}': table has no primary key", 'alter_error');
			}

			return [$this->compileDropExistingPrimaryKey($tableName, $existingPrimaryKeyConstraintName)];
		}

		private function assertPrimaryKeyChangesSupported(string $tableName): void {
			if ($this->platform->getDatabaseType() === 'sqlite') {
				throw new QuelException(
					"Cannot change the primary key on '{$tableName}': SQLite has no ALTER TABLE support for " .
					"primary-key changes — this requires rebuilding the table, which 'alter' does not attempt " .
					"(see objectquel-alter-table-design.md, 'Multi-engine retype safety')",
					'alter_unsupported'
				);
			}
		}

		/**
		 * MySQL/MariaDB need no constraint name (`DROP PRIMARY KEY` is a
		 * fixed clause); pgsql/sqlsrv name their primary key as an
		 * ordinary named constraint and must `DROP CONSTRAINT` it, using
		 * the name the caller resolved via schema introspection.
		 */
		private function compileDropExistingPrimaryKey(string $tableName, ?string $existingPrimaryKeyConstraintName): string {
			if (in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'], true)) {
				return sprintf('ALTER TABLE %s DROP PRIMARY KEY', $this->quotedTable($tableName));
			}

			if ($existingPrimaryKeyConstraintName === null) {
				throw new QuelException(
					"Cannot change the primary key on '{$tableName}': the table has a primary key but its constraint name could not be resolved",
					'alter_error'
				);
			}

			return sprintf(
				'ALTER TABLE %s DROP CONSTRAINT %s',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifier($existingPrimaryKeyConstraintName)
			);
		}

		/**
		 * `add foreign key (col) references Table (col) [on delete action]
		 * [on update action]` — one native `ALTER TABLE ... ADD CONSTRAINT
		 * ... FOREIGN KEY ...` statement, same as three of the four
		 * supported dialects natively support (see
		 * objectquel-foreign-key-design.md, "Dialect reality"). The
		 * constraint name is always the derived one — never
		 * author-supplied (see objectquel-foreign-key-design.md, decision 1).
		 */
		private function compileAddForeignKey(string $tableName, AstAlterAddForeignKey $operation): string {
			$this->assertForeignKeyChangesSupported($tableName);

			$name = ForeignKeyConstraintNamer::name($tableName, $operation->getColumn());

			return sprintf(
				'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
				$this->quotedTable($tableName),
				$this->identifierQuoter->quoteIdentifier($name),
				$this->identifierQuoter->quoteIdentifier($operation->getColumn()),
				$this->identifierQuoter->quoteIdentifier($operation->getReferencedTable()),
				$this->identifierQuoter->quoteIdentifier($operation->getReferencedColumn()),
				$operation->getOnDelete(),
				$operation->getOnUpdate()
			);
		}

		/**
		 * `drop foreign key (col)` — targets by column, per
		 * objectquel-foreign-key-design.md, decision 1, resolving to the
		 * same derived name `add foreign key` would have used for that
		 * column. MySQL/MariaDB use the dedicated `DROP FOREIGN KEY`
		 * clause; pgsql/sqlsrv use the general-purpose `DROP CONSTRAINT`
		 * (both name their FK as an ordinary named constraint).
		 */
		private function compileDropForeignKey(string $tableName, AstAlterDropForeignKey $operation): string {
			$this->assertForeignKeyChangesSupported($tableName);

			$quotedName = $this->identifierQuoter->quoteIdentifier(
				ForeignKeyConstraintNamer::name($tableName, $operation->getColumn())
			);

			if (in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'], true)) {
				return sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $this->quotedTable($tableName), $quotedName);
			}

			return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->quotedTable($tableName), $quotedName);
		}

		/**
		 * SQLite's ALTER TABLE cannot add or drop a foreign key at all,
		 * ever — not even via a rebuild-avoiding trick; FK constraints on
		 * SQLite can only be declared inline in CREATE TABLE (see
		 * objectquel-foreign-key-design.md, "Dialect reality"). Same
		 * 'alter_unsupported' treatment retype/primary-key changes already
		 * get.
		 */
		private function assertForeignKeyChangesSupported(string $tableName): void {
			if ($this->platform->getDatabaseType() === 'sqlite') {
				throw new QuelException(
					"Cannot change foreign keys on '{$tableName}': SQLite has no ALTER TABLE support for " .
					"adding or dropping foreign keys — they can only be declared inline in CREATE TABLE, " .
					"which 'alter' does not attempt (see objectquel-foreign-key-design.md, 'Dialect reality')",
					'alter_unsupported'
				);
			}
		}

		/**
		 * Renders a full column definition (type + NOT NULL + identity) via
		 * DDLTypeMapper, the same per-dialect renderer `create` uses. PK
		 * membership never folds into $notNull here the way QuelToSQLCreate
		 * does — `add`/`retype` never implicitly make a column part of the
		 * primary key; that's always a separate, explicit
		 * `primary key (...)` sub-operation.
		 */
		private function renderColumnDefinition(AstColumnDefinition $column): string {
			return $this->ddlTypeMapper->renderColumnDefinition(
				$this->identifierQuoter->quoteIdentifier($column->getName()),
				$column->toColumnDefinitionArray(),
				$column->isNotNull(),
				$column->isIdentity()
			);
		}
	}
