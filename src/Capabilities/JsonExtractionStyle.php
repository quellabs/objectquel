<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	/**
	 * Describes the syntax style used to extract a text value from a JSON column.
	 *
	 * - JsonUnquote     → JSON_UNQUOTE(JSON_EXTRACT(col, '$.a.b'))
	 *                     Supported by: MySQL (all versions), MariaDB < 10.9, SQLite < 3.38
	 *
	 * - HashDoubleArrow → col #>> '{a,b}'
	 *                     Supported by: PostgreSQL (all versions)
	 *
	 * - JsonValue       → JSON_VALUE(col, '$.a.b')
	 *                     Supported by: MariaDB >= 10.9
	 *                     SQLite has no JSON_VALUE() function at any version — use
	 *                     ArrowOperator for SQLite >= 3.38 instead.
	 *
	 * - ArrowOperator   → col ->> '$.a.b'
	 *                     Supported by: SQLite >= 3.38 (the only engine targeted
	 *                     here that has this operator; MySQL/MariaDB/PostgreSQL
	 *                     use the styles above instead even though some of them
	 *                     also have their own -> / ->> operators with different
	 *                     semantics)
	 */
	enum JsonExtractionStyle {
		case JsonUnquote;
		case HashDoubleArrow;
		case JsonValue;
		case ArrowOperator;
	}