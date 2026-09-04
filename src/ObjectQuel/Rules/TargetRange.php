<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Resolves a write verb's target range name to a declared AstRangeDatabase
	 * — shared by `replace` and `delete`, which (unlike `append`) always need
	 * a concrete range to resolve their mandatory WHERE clause's identifiers
	 * against, so neither supports append's bare-entity-name fallback: the
	 * target must already be declared via `range of <name> is <Entity>`, and
	 * must be a real persisted entity range, not a subquery/temp-table/JSON
	 * range (see objectquel-replace-plan.md / objectquel-delete-plan.md).
	 */
	class TargetRange {

		/**
		 * @param string $name The range name written after the statement keyword
		 * @param AstRange[] $ranges Ranges already declared ahead of this statement
		 * @param string $statementName Used only to produce a readable error message (e.g. "replace")
		 * @return AstRangeDatabase
		 * @throws ParserException
		 */
		public static function resolve(string $name, array $ranges, string $statementName): AstRangeDatabase {
			foreach ($ranges as $range) {
				if ($range->getName() !== $name) {
					continue;
				}

				if (!$range instanceof AstRangeDatabase) {
					throw new ParserException("{$statementName} target '{$name}' must be a database entity range");
				}

				return $range;
			}

			throw new ParserException("Undefined range reference '{$name}' in {$statementName} statement. Make sure the range is declared before it is used.");
		}
	}
