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
			return $this->isInterval() ? 'interval' : 'datetime';
		}
		
		/**
		 * Returns true if this is an interval ("1 day", etc.)
		 * Return false if it's a date
		 * @return bool
		 */
		public function isInterval(): bool {
			return $this->foldedSeconds !== null;
		}
	}