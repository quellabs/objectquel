<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Parses QUEL interval strings into integer seconds.
	 *
	 * An interval string is a human-readable duration expression accepted by the
	 * date() function when the argument is not a column reference or "now":
	 *
	 *   "6 days"              → 518400
	 *   "2 hours"             → 7200
	 *   "4 years 20 minutes"  → 126721200
	 *   "1 day 2 hours 30 minutes" → 95400
	 *
	 * Both singular and plural unit names are accepted ("day" / "days", etc.).
	 * Negative amounts are supported for internal use ("−30 days" → −2592000).
	 *
	 * "now" and empty strings return null — they are not intervals and the caller
	 * decides how to handle them. Any other unrecognised input throws ParserException.
	 */
	class IntervalParser {
		
		/**
		 * Supported interval unit names mapped to their duration in seconds.
		 * Singular and plural forms are both listed explicitly.
		 * @var array<string, int>
		 */
		private const array UNIT_SECONDS = [
			'second'  => 1,
			'seconds' => 1,
			'minute'  => 60,
			'minutes' => 60,
			'hour'    => 3600,
			'hours'   => 3600,
			'day'     => 86400,
			'days'    => 86400,
			'week'    => 604800,
			'weeks'   => 604800,
			'month'   => 2592000,   // 30 days
			'months'  => 2592000,
			'year'    => 31536000,  // 365 days
			'years'   => 31536000,
		];
		
		/**
		 * Attempts to parse an interval string and return the total number of seconds.
		 *
		 * Returns null for "now" and empty strings — these are not intervals and
		 * the caller handles them separately.
		 *
		 * Throws ParserException when:
		 *   - The string contains no recognisable "N unit" pairs (e.g. "yesterday")
		 *   - Any pair uses an unrecognised unit name (e.g. "1 bananas")
		 *   - The string contains leftover tokens beyond the recognised pairs
		 *     (e.g. "6 days ago" — "ago" is not a unit)
		 *
		 * @param string $value  Raw interval string, e.g. "6 days" or "4 years 20 minutes"
		 * @return int|null      Total seconds, or null when the value is "now" or empty
		 * @throws ParserException When the string is not a valid interval
		 */
		public function parse(string $value): ?int {
			$value = trim($value);
			
			// Empty string and "now" are not intervals — return null so the caller
			// can handle them (e.g. emit the platform NOW() function).
			if ($value === '' || strtolower($value) === 'now') {
				return null;
			}
			
			// Match all "<number> <unit>" pairs in the string.
			// QUEL supports composite intervals like "4 years 20 minutes".
			$count = preg_match_all('/(-?\d+(?:\.\d+)?)\s+([a-z]+)/i', $value, $matches, PREG_SET_ORDER);
			
			if (!$count) {
				throw new ParserException("Invalid interval expression: \"{$value}\". Expected a format like \"6 days\" or \"4 years 20 minutes\".");
			}
			
			// Verify the matched pairs reconstruct the full input so that leftover
			// tokens like "ago" in "6 days ago" are caught rather than silently ignored.
			$reconstructed = '';
			
			foreach ($matches as $match) {
				$reconstructed .= ($reconstructed === '' ? '' : ' ') . $match[1] . ' ' . $match[2];
			}
			
			if (strtolower($reconstructed) !== strtolower($value)) {
				throw new ParserException("Invalid interval expression: \"{$value}\". Unrecognised tokens found after parsing known components.");
			}
			
			// Sum the seconds for every pair, rejecting unknown unit names.
			$total = 0;
			
			foreach ($matches as $match) {
				$amount = (float) $match[1];
				$unit   = strtolower($match[2]);
				
				if (!isset(self::UNIT_SECONDS[$unit])) {
					throw new ParserException("Unknown interval unit \"{$match[2]}\" in \"{$value}\". Supported units: second(s), minute(s), hour(s), day(s), week(s), month(s), year(s).");
				}
				
				$total += (int) round($amount * self::UNIT_SECONDS[$unit]);
			}
			
			return $total;
		}
	}