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
	 *                     Supported by: MariaDB >= 10.9, SQLite >= 3.38
	 */
	enum JsonExtractionStyle {
		case JsonUnquote;
		case HashDoubleArrow;
		case JsonValue;
	}