<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;

	/**
	 * Resolves a write verb's target range name to a declared AstRangeDatabase
	 * or AstRangeTable — shared by `replace` and `delete`, which (unlike
	 * `append`) always need a concrete range to resolve their mandatory WHERE
	 * clause's identifiers against, so neither supports append's
	 * bare-entity-name fallback: the target must already be declared via
	 * `range of <name> is <Entity>`/`range of <name> is table <Name>`, and
	 * must be a real persisted entity range or plain-table range, not a
	 * subquery/temp-table/JSON range (see objectquel-replace-plan.md /
	 * objectquel-delete-plan.md / objectquel-plain-table-range-plan.md).
	 */
	class TargetRange {

		/**
		 * @param string $name The range name written after the statement keyword
		 * @param AstRange[] $ranges Ranges already declared ahead of this statement
		 * @param string $statementName Used only to produce a readable error message (e.g. "replace")
		 * @return AstRangeDatabase|AstRangeTable
		 * @throws ParserException
		 */
		public static function resolve(string $name, array $ranges, string $statementName): AstRangeDatabase|AstRangeTable {
			foreach ($ranges as $range) {
				if ($range->getName() !== $name) {
					continue;
				}

				if (!$range instanceof AstRangeDatabase && !$range instanceof AstRangeTable) {
					throw new ParserException("{$statementName} target '{$name}' must be a database entity range or a plain-table range");
				}

				return $range;
			}

			throw new ParserException("Undefined range reference '{$name}' in {$statementName} statement. Make sure the range is declared before it is used.");
		}

		/**
		 * Same resolution as resolve(), but also accepts a JSON-source range —
		 * used only by `append` (see Rules\Append and
		 * objectquel-json-append-plan.md). Kept as a separate method rather
		 * than widening resolve() itself so `replace`/`delete` stay unable to
		 * target a JSON range by construction, not by convention: they still
		 * call resolve(), which never learns about AstRangeJsonSource.
		 * @param string $name The range name written after the statement keyword
		 * @param AstRange[] $ranges Ranges already declared ahead of this statement
		 * @param string $statementName Used only to produce a readable error message (e.g. "append")
		 * @return AstRangeDatabase|AstRangeTable|AstRangeJsonSource
		 * @throws ParserException
		 */
		public static function resolveForAppend(string $name, array $ranges, string $statementName): AstRangeDatabase|AstRangeTable|AstRangeJsonSource {
			foreach ($ranges as $range) {
				if ($range->getName() !== $name) {
					continue;
				}

				if (!$range instanceof AstRangeDatabase && !$range instanceof AstRangeTable && !$range instanceof AstRangeJsonSource) {
					throw new ParserException("{$statementName} target '{$name}' must be a database entity range, a plain-table range, or a JSON source range");
				}

				return $range;
			}

			throw new ParserException("Undefined range reference '{$name}' in {$statementName} statement. Make sure the range is declared before it is used.");
		}
	}
