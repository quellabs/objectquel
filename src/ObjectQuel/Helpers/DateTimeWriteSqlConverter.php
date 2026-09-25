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

			return in_array($valueType, self::TIMESTAMP_TYPES, true) ? $platform->getDatetimeFromUnixTimestamp($valueSql) : $valueSql;
		}
	}
