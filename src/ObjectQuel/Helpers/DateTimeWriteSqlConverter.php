<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Exception\SemanticException;

	/**
	 * Stores Unix timestamps (numbers, date() and date arithmetic) into datetime columns and variables as native datetimes.
	 */
	class DateTimeWriteSqlConverter {

		/** Inferred types whose value is a Unix timestamp; 'datetime' is what date() and date arithmetic produce */
		private const array TIMESTAMP_TYPES = ['datetime', 'int', 'integer', 'float'];

		/**
		 * Converts Unix timestamp expressions written to datetime targets.
		 * @param string $valueSql Compiled value
		 * @param string|null $valueType Inferred PHP-level type of the value, after NormalizeDateTime
		 * @param string|null $targetType PHP-level type of the column or variable receiving it
		 * @param string $target Column or variable name, for the error message
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @return string The value, converted to a datetime when a Unix timestamp goes into a datetime
		 * @throws SemanticException When an interval goes into a datetime
		 */
		public static function convert(string $valueSql, ?string $valueType, ?string $targetType, string $target, PlatformCapabilitiesInterface $platform): string {
			if ($targetType !== '\DateTime') {
				return $valueSql;
			}

			if ($valueType === 'interval') {
				throw new SemanticException("'{$target}' is a datetime, but the value written to it is an interval. Add it to a point in time, e.g. date(\"now\") + date(\"1 day\").");
			}

			return in_array($valueType, self::TIMESTAMP_TYPES, true) ? self::datetimeFromUnixTimestamp($platform->getDatabaseType(), $valueSql) : $valueSql;
		}

		/**
		 * Converts Unix seconds to a native datetime expression.
		 * @param string $databaseType Database engine identifier
		 * @param string $timestampSql Unix seconds expression
		 * @return string SQL expression
		 */
		private static function datetimeFromUnixTimestamp(string $databaseType, string $timestampSql): string {
			return match ($databaseType) {
				'pgsql' => "(TO_TIMESTAMP({$timestampSql}) AT TIME ZONE 'UTC')",
				'sqlite' => "datetime({$timestampSql}, 'unixepoch')",
				'sqlsrv' => "DATEADD(SECOND, CAST({$timestampSql} AS BIGINT) % 86400, DATEADD(DAY, CAST({$timestampSql} AS BIGINT) / 86400, CAST('1970-01-01' AS DATETIME2)))",
				default => "FROM_UNIXTIME({$timestampSql})",
			};
		}
	}
