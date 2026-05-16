<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	/**
	 * Describes the fulltext search mechanism available on the current platform.
	 *
	 * Different database engines implement fulltext search in fundamentally
	 * different ways, requiring distinct SQL generation strategies:
	 *
	 * - FULLTEXT  MySQL / MariaDB / SQL Server: a FULLTEXT index on one or more
	 *             columns, queried with MATCH(col) AGAINST('term').
	 *
	 * - FTS5      SQLite: a separate FTS5 virtual table (or FTS4 on older builds),
	 *             queried with a JOIN and a MATCH predicate against the virtual table.
	 *
	 * - TSVECTOR  PostgreSQL: a tsvector column with a GIN index, queried with
	 *             col @@ to_tsquery('term') — no FULLTEXT INDEX DDL involved.
	 */
	enum FulltextIndexStyle: string {
		
		/** MySQL, MariaDB, SQL Server — MATCH(col) AGAINST('term') */
		case Fulltext = 'fulltext';
		
		/** SQLite FTS5 virtual table — MATCH predicate on a shadow table */
		case Fts5 = 'fts5';
		
		/** PostgreSQL tsvector/GIN — col @@ to_tsquery('term') */
		case Tsvector = 'tsvector';
	}