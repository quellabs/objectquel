<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents a date() function call in the AST.
	 *
	 * date() converts its argument to a Unix timestamp (integer seconds since
	 * the epoch) so that temporal arithmetic can be expressed as plain integer
	 * math, which every SQL engine handles identically without dialect branching.
	 *
	 * Accepted argument forms:
	 *   date("now")          → platform NOW() function as Unix timestamp
	 *   date("6 days")       → integer literal 518400 (folded at parse time)
	 *   date("2 hours")      → integer literal 7200   (folded at parse time)
	 *   date(o.orderDate)    → UNIX_TIMESTAMP(col) / strftime('%s',col) / EXTRACT(…)
	 *   date(:param)         → same as column, but with a parameter placeholder
	 *
	 * Pure interval strings ("N unit") are pre-computed to integer seconds at
	 * parse time and stored in $foldedSeconds so the SQL generator can emit a
	 * bare integer literal without any function call. "now" and expression
	 * arguments are emitted as platform-specific SQL at generation time.
	 *
	 * Arithmetic between date() values falls through to the existing AstTerm /
	 * AstFactor paths unchanged — no special handling is needed there since the
	 * operands are all integers at that point.
	 */
	class AstDate extends Ast {
		
		/**
		 * The inner expression passed to date().
		 * May be AstString ("now" or an interval), AstIdentifier (a datetime
		 * column), or AstParameter (a runtime-bound value).
		 * @var AstInterface
		 */
		private AstInterface $expression;
		
		/**
		 * Pre-computed seconds for pure interval strings, e.g. "6 days" → 518400.
		 * null when the argument is "now", a column reference, or a parameter.
		 * @var int|null
		 */
		private ?int $foldedSeconds;
		
		/**
		 * Supported interval unit names mapped to their duration in seconds.
		 * Singular and plural forms are both accepted ("day" and "days").
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
		 * @param AstInterface $expression  The parsed argument to date()
		 * @param int|null     $foldedSeconds  Pre-computed seconds for pure intervals; null otherwise
		 */
		public function __construct(AstInterface $expression, ?int $foldedSeconds) {
			$this->expression    = $expression;
			$this->foldedSeconds = $foldedSeconds;
			
			$expression->setParent($this);
		}
		
		/**
		 * Accepts a visitor. Visits this node first, then the inner expression so
		 * that tree-walking visitors (range collectors, validators, etc.) can reach
		 * the argument even when it is an identifier.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Deep-clones this node and its inner expression.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->expression->deepClone(), $this->foldedSeconds);
		}
		
		/**
		 * Returns the inner expression passed to date().
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Returns pre-computed seconds when the argument was a pure interval
		 * string, or null for "now", column references, and parameters.
		 * @return int|null
		 */
		public function getFoldedSeconds(): ?int {
			return $this->foldedSeconds;
		}
		
		/**
		 * Returns true when the argument was "now" — i.e. the node should emit
		 * the platform's current-timestamp function rather than an integer literal.
		 * @return bool
		 */
		public function isNow(): bool {
			return $this->foldedSeconds === null
				&& $this->expression instanceof AstString
				&& strtolower(trim($this->expression->getValue())) === 'now';
		}
		
		/**
		 * date() always produces an integer (Unix timestamp in seconds).
		 * @return string
		 */
		public function getReturnType(): string {
			return 'datetime';
		}
		
		/**
		 * Attempts to parse an interval string of the form "N unit" and return
		 * the equivalent number of seconds, or null if the string is not a
		 * recognised interval (e.g. it is "now" or an unparseable value).
		 *
		 * @param string $value  The raw string argument, e.g. "6 days" or "2 hours"
		 * @return int|null      Seconds, or null when not an interval
		 */
		public static function tryParseInterval(string $value): ?int {
			$value = trim($value);
			
			// "now" is not an interval
			if (strtolower($value) === 'now') {
				return null;
			}
			
			// Match all "<number> <unit>" pairs in the string.
			// QUEL supports composite intervals like "4 years 20 minutes".
			$count = preg_match_all('/(-?\d+(?:\.\d+)?)\s+([a-z]+)/i', $value, $matches, PREG_SET_ORDER);
			
			// No pairs found, or the matched content doesn't cover the entire trimmed
			// string — reject to avoid silently ignoring garbage like "6 bananas ago".
			if (!$count) {
				return null;
			}
			
			// Verify the matched pairs reconstruct the full input (no leftover tokens).
			$reconstructed = '';
			
			foreach ($matches as $match) {
				$reconstructed .= ($reconstructed === '' ? '' : ' ') . $match[1] . ' ' . $match[2];
			}
			
			if (strtolower($reconstructed) !== strtolower($value)) {
				return null;
			}
			
			// Sum the seconds for every pair.
			$total = 0;
			
			foreach ($matches as $match) {
				$amount = (float) $match[1];
				$unit   = strtolower($match[2]);
				
				if (!isset(self::UNIT_SECONDS[$unit])) {
					return null;
				}
				
				$total += (int) round($amount * self::UNIT_SECONDS[$unit]);
			}
			
			return $total;
		}
	}