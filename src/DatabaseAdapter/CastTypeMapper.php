<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;

	/**
	 * Maps canonical QUEL cast type keywords ('int', 'float', 'string',
	 * 'decimal', 'bool') to the SQL type token that should appear inside a
	 * CAST(... AS <token>) expression on the connected engine.
	 *
	 * Like DDLTypeMapper (temp-table DDL) and SqlIdentifierQuoter (identifier
	 * quoting), this renders SQL text from a PlatformCapabilitiesInterface's
	 * reported facts rather than living on PlatformCapabilities itself — a
	 * per-abstract-type lookup table is a different kind of thing than a
	 * capability fact. Used by SemanticAnalyzer (to validate a cast type is
	 * supported before it reaches SQL generation) and BuildSqlFromAst (to
	 * render the CAST() expression).
	 */
	class CastTypeMapper {

		/**
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * CastTypeMapper constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->platform = $platform;
		}

		/**
		 * Returns the set of QUEL cast type names supported by the connected
		 * engine, mapped to the SQL type token that should appear inside the
		 * CAST expression.
		 *
		 * The keys are the identifiers users write in QUEL (e.g. 'int', 'float',
		 * 'string', 'decimal'). The values are the exact SQL type tokens emitted
		 * into the generated SQL (e.g. 'SIGNED', 'DOUBLE', 'CHAR', 'DECIMAL').
		 * SemanticAnalyzer validates cast types against this map and rejects any
		 * cast whose key is absent, so only engine-supported casts reach
		 * BuildSqlFromAst's SQL generator.
		 *
		 * Cast type maps per engine:
		 *
		 * MySQL / MariaDB
		 *   Integer arithmetic uses SIGNED (signed 64-bit) rather than INT because
		 *   CAST(x AS INT) is not valid in MySQL; SIGNED / UNSIGNED are the correct
		 *   integer target types for CAST(). Also the fallback for any other
		 *   database type value this class doesn't otherwise recognise.
		 *
		 * PostgreSQL
		 *   Uses standard ANSI type names. INTEGER and FLOAT are the idiomatic choices;
		 *   TEXT is preferred over VARCHAR (no length constraint) for string casts.
		 *
		 * SQLite
		 *   SQLite CAST() accepts a limited set of type affinities: INTEGER, REAL,
		 *   TEXT, NUMERIC, BLOB. There is no separate FLOAT or DOUBLE type.
		 *
		 * SQL Server
		 *   T-SQL has no SIGNED/DOUBLE types at all (those are MySQL-only CAST
		 *   keywords) — INT and FLOAT are the correct targets. CHAR is avoided for
		 *   string casts: CAST(x AS CHAR) with no length silently defaults to
		 *   CHAR(30) in T-SQL (unlike MySQL, where bare CHAR doesn't truncate), so
		 *   NVARCHAR(MAX) is used instead. BIT is included (unlike MySQL/MariaDB,
		 *   which have no CAST-to-boolean target at all) since SQL Server does have
		 *   a real boolean-ish type here.
		 *
		 * @return array<string, string> e.g. ['int' => 'SIGNED', 'float' => 'DOUBLE', ...]
		 */
		public function getSupportedCastTypes(): array {
			return match ($this->platform->getDatabaseType()) {
				'pgsql' => [
					'int'     => 'INTEGER',
					'float'   => 'FLOAT',
					'string'  => 'TEXT',
					'decimal' => 'DECIMAL',
					'bool'    => 'BOOLEAN',
				],
				'sqlite' => [
					'int'     => 'INTEGER',
					'float'   => 'REAL',
					'string'  => 'TEXT',
					'decimal' => 'NUMERIC',
				],
				'sqlsrv' => [
					'int'     => 'INT',
					'float'   => 'FLOAT',
					'string'  => 'NVARCHAR(MAX)',
					'decimal' => 'DECIMAL',
					'bool'    => 'BIT',
				],
				// MySQL/MariaDB, and the fallback for any other database type
				default => [
					'int'     => 'SIGNED',
					'float'   => 'DOUBLE',
					'string'  => 'CHAR',
					'decimal' => 'DECIMAL',
				],
			};
		}
	}
