<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Normalizer;
	
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\IntervalParser;
	
	/**
	 * IntervalFormatter normalizes integer second counts to human-readable interval
	 * strings and denormalizes those strings back to seconds.
	 *
	 * This is the display-layer complement to IntervalParser — it converts the
	 * raw integer that date() arithmetic produces into a string in the same format
	 * that IntervalParser accepts:
	 *
	 *   172800  → "2 days"
	 *   158400  → "1 day 20 hours"
	 *   90      → "1 minute 30 seconds"
	 *   0       → "0 seconds"
	 *
	 * normalize()    integer seconds → human-readable string
	 * denormalize()  human-readable string → integer seconds
	 *
	 * Units are emitted largest-first and pluralised correctly. Only non-zero
	 * components are included. Negative values are prefixed with a minus sign.
	 */
	class IntervalNormalizer implements NormalizerInterface {
		
		/**
		 * Unit breakdown from largest to smallest.
		 * Each entry is [unit_name_singular, unit_name_plural, seconds].
		 * @var array<int, array{0: string, 1: string, 2: int}>
		 */
		private const array UNITS = [
			['year',   'years',   31536000],
			['month',  'months',   2592000],
			['week',   'weeks',     604800],
			['day',    'days',       86400],
			['hour',   'hours',       3600],
			['minute', 'minutes',       60],
			['second', 'seconds',        1],
		];
		
		/**
		 * Parameters as passed by the annotation
		 * @var array<string, mixed>
		 * @phpstan-ignore-next-line property.onlyWritten
		 */
		private array $parameters;
		
		/**
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters = []) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Converts an integer number of seconds to a human-readable interval string.
		 *
		 * @param mixed $value  Raw integer or numeric string from the database
		 * @return string|null  E.g. "1 day 20 hours", or null for null input
		 */
		public function normalize(mixed $value): ?string {
			if ($value === null) {
				return null;
			}
			
			if (!is_numeric($value)) {
				return null;
			}
			
			$seconds = (int) $value;
			$negative = $seconds < 0;
			$remaining = abs($seconds);
			$parts = [];
			
			foreach (self::UNITS as [$singular, $plural, $unitSeconds]) {
				if ($remaining >= $unitSeconds) {
					$count = intdiv($remaining, $unitSeconds);
					$remaining %= $unitSeconds;
					$parts[] = $count . ' ' . ($count === 1 ? $singular : $plural);
				}
			}
			
			if (empty($parts)) {
				return '0 seconds';
			}
			
			$result = implode(' ', $parts);
			return $negative ? '-' . $result : $result;
		}
		
		/**
		 * Converts a human-readable interval string back to integer seconds.
		 * Delegates to IntervalParser. Returns null for null input or
		 * unrecognisable strings.
		 *
		 * @param mixed $value  E.g. "1 day 20 hours"
		 * @return int|null     Total seconds, or null on failure
		 */
		public function denormalize(mixed $value): ?int {
			if ($value === null) {
				return null;
			}
			
			if (!is_string($value)) {
				return null;
			}
			
			try {
				return (new IntervalParser())->parse($value);
			} catch (ParserException) {
				return null;
			}
		}
	}