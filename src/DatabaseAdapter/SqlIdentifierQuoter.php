<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;

	/**
	 * Quotes SQL identifiers and aliases for whichever engine a
	 * PlatformCapabilitiesInterface describes.
	 *
	 * This is a general SQL-rendering concern, not a DDL one — every generated
	 * statement needs identifiers quoted (SELECT, JOIN, INSERT, CREATE, all of
	 * it), so this stays separate from DDLTypeMapper, which is specifically
	 * about temporary-table DDL for TempTableExecutor. QuelToSQL (the QUEL→SQL
	 * compiler, which never emits DDL) depends on this class, not on
	 * DDLTypeMapper, for exactly that reason.
	 *
	 * Deliberately does not delegate to DatabaseAdapter::escapeIdentifier()
	 * (used throughout the Persistence\* classes for INSERT/UPDATE/DELETE),
	 * even though both ultimately do "wrap in this engine's quote characters".
	 * escapeIdentifier() delegates to the connected CakePHP driver's own
	 * quoter, which is unsafe for two things this class is specifically used
	 * for:
	 *   - Alias quoting: the driver's quoter splits any '.' into a qualified
	 *     `x`.`id` reference (it's built for real table.column identifiers).
	 *     QuelToSQL deliberately builds single-token aliases containing a
	 *     literal '.' (e.g. 'x.id'); splitting them produces invalid SQL in
	 *     alias position. See quoteIdentifier().
	 *   - SQL Server temp table names: the driver's quoter's regexes all
	 *     require the identifier to start with a word character, so a
	 *     '#'-prefixed local temp table name (see DDLTypeMapper::getTempTableName())
	 *     matches none of them and falls through to being returned completely
	 *     UNQUOTED — verified directly against Cake\Database\IdentifierQuoter:
	 *     quoteIdentifier('#tmp_x') returns '#tmp_x', not '[#tmp_x]'.
	 *   - SQLite double-quote fallback: SQLite silently reinterprets an
	 *     unresolvable double-quoted token as a string literal instead of
	 *     raising an error (kept for compatibility with older code that used
	 *     double quotes for string literals); backtick-quoted tokens have no
	 *     such fallback. CakePHP's SQLite driver uses double quotes, so
	 *     escapeIdentifier() carries this risk on SQLite; this class uses
	 *     backticks for SQLite instead (grouped with MySQL/MariaDB below).
	 * All three failure modes above are silent (no exception, just wrong or
	 * unquoted SQL, or SQL that quietly changes meaning),
	 * which is why this class always does the simple, predictable wrap rather
	 * than reusing the driver's smarter-but-unsafe-for-us quoter. It's also
	 * the only option for QuelToSQL, which is deliberately never given a live
	 * DatabaseAdapter/connection (see its own docblock) and so has no way to
	 * reach escapeIdentifier() regardless.
	 */
	class SqlIdentifierQuoter {

		/**
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * SqlIdentifierQuoter constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->platform = $platform;
		}

		/**
		 * Quotes a table, column, or alias identifier for safe inclusion in
		 * generated SQL.
		 *
		 * Wraps the identifier as a single opaque token in the connected engine's
		 * quote characters — MySQL/MariaDB/SQLite use backticks, PostgreSQL uses
		 * double quotes, SQL Server uses square brackets. Always treats the input
		 * as one literal token and never splits on '.' into a qualified
		 * table.column reference.
		 *
		 * That "never split" guarantee is load-bearing for QuelToSQL's alias
		 * positions: it deliberately builds alias names containing a literal '.'
		 * (e.g. 'x.id', so a subquery's flattened columns can be looked up by the
		 * outer range+property they came from) that must survive quoting as one
		 * token — a qualifier-aware quoter would instead treat 'x.id' as a
		 * qualified reference and emit the invalid `x`.`id` in alias position.
		 * Real table/column names never contain '.', so the same guarantee is a
		 * no-op (and therefore harmless) when this is used for those instead.
		 *
		 * @param string $identifier Unquoted identifier or alias text, which may itself contain '.'
		 * @return string The identifier wrapped in the engine's quote characters
		 */
		public function quoteIdentifier(string $identifier): string {
			return match ($this->platform->getDatabaseType()) {
				'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
				'sqlsrv' => '[' . str_replace(']', ']]', $identifier) . ']',
				// mysql/mariadb/sqlite: see the SQLite double-quote note above.
				default => '`' . str_replace('`', '``', $identifier) . '`',
			};
		}
	}
