<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * Coerces bound parameter values that are compared against a \DateTime column
	 * to a Unix timestamp integer, matching the column side's UNIX_TIMESTAMP() /
	 * strftime('%s', ...) / EXTRACT(EPOCH FROM ...) conversion.
	 *
	 * NormalizeDateTime wraps a bare \DateTime column operand of a comparison in
	 * AstDate so it compiles to an integer Unix timestamp in SQL — but it leaves
	 * the other operand untouched. When that other operand is a raw parameter
	 * placeholder, the caller's bound value (a DateTimeInterface, a formatted
	 * "Y-m-d H:i:s" / "Y-m-d" string, or an int) never gets the same treatment,
	 * so the comparison silently compares an integer column against a mismatched
	 * value. This visitor must run after NormalizeDateTime, once AstDate nodes
	 * exist, and needs the runtime parameter array to mutate the bound value.
	 *
	 * Only bare AstParameter operands are touched. A parameter already wrapped
	 * in an explicit date(:param) call keeps its documented contract (the SQL
	 * function is applied to the placeholder itself) and is left alone here.
	 */
	class CoerceDateTimeParameters implements AstVisitorInterface {

		/**
		 * Comparison operators eligible for coercion. Arithmetic operators are
		 * excluded — mixing a temporal value with a plain scalar there is a
		 * semantic error caught separately by ValidateNoTemporalScalarMix.
		 */
		private const array COMPARISON_OPERATORS = ['=', '<>', '>', '>=', '<', '<='];

		/** @var array<string, mixed> Reference to the runtime parameter array */
		private array $parameters;

		/**
		 * @param array<string, mixed> $parameters Reference to the query's bound parameters
		 */
		public function __construct(array &$parameters) {
			$this->parameters = &$parameters;
		}

		/**
		 * @param AstInterface $node
		 * @return void
		 * @throws QuelException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof NodeBinary || !in_array($node->getOperator(), self::COMPARISON_OPERATORS, true)) {
				return;
			}

			$this->coerceIfNeeded($node->getLeft(), $node->getRight());
			$this->coerceIfNeeded($node->getRight(), $node->getLeft());
		}

		/**
		 * When $dateSide is a column-derived AstDate and $paramSide is a bare
		 * parameter, coerce that parameter's bound value in place.
		 * @param AstInterface $dateSide
		 * @param AstInterface $paramSide
		 * @return void
		 * @throws QuelException
		 */
		private function coerceIfNeeded(AstInterface $dateSide, AstInterface $paramSide): void {
			if (!$dateSide instanceof AstDate || $dateSide->isInterval() || $dateSide->isNow()) {
				return;
			}

			if (!$paramSide instanceof AstParameter) {
				return;
			}

			$name = $paramSide->getName();

			if (!array_key_exists($name, $this->parameters)) {
				return;
			}

			$this->parameters[$name] = $this->coerceValue($this->parameters[$name], $name);
		}

		/**
		 * Converts a bound value into a Unix timestamp integer.
		 * @param mixed $value
		 * @param string $paramName
		 * @return int
		 * @throws QuelException
		 */
		private function coerceValue(mixed $value, string $paramName): int {
			if ($value instanceof \DateTimeInterface) {
				return $value->getTimestamp();
			}

			if (is_int($value)) {
				return $value;
			}

			if (is_string($value)) {
				$trimmed = trim($value);

				// A bare integer string ("1754...") is already a Unix timestamp.
				if ($trimmed !== '' && preg_match('/^-?\d+$/', $trimmed)) {
					return (int) $trimmed;
				}

				// Pad a bare date string to midnight, same convention as ConditionEvaluator.
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
					$trimmed .= ' 00:00:00';
				}

				$dt = \DateTime::createFromFormat('Y-m-d H:i:s', $trimmed);

				if ($dt !== false) {
					return $dt->getTimestamp();
				}
			}

			throw new QuelException(sprintf(
				"Parameter ':%s' is compared against a \\DateTime column and must be a DateTimeInterface, " .
				"a 'Y-m-d H:i:s' / 'Y-m-d' string, or a Unix timestamp integer — got %s.",
				$paramName,
				is_object($value) ? get_class($value) : var_export($value, true)
			), 'type_error');
		}
	}
