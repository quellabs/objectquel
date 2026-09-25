<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	
	/**
	 * Quotes a SET-clause target column for `replace`/`delete`-as-update SQL,
	 * qualifying it with the statement's own range alias only where the
	 * connected engine allows a qualified column on the left side of a SET
	 * assignment — PostgreSQL and SQLite reject `SET alias.col = ...` there,
	 * so those always get the bare column regardless of $qualifyWithAlias.
	 */
	class SetTargetColumnQuoter {
		
		/**
		 * Quotes a SET target column with an allowed range alias, if applicable.
		 * @param string $columnName
		 * @param string|null $qualifyWithAlias The statement's own range alias, or
		 *        null to always render bare (e.g. an on-conflict UPDATE with no
		 *        alias in scope).
		 * @param SqlIdentifierQuoter $identifierQuoter
		 * @param PlatformCapabilitiesInterface $platform
		 * @return string
		 */
		public static function quote(
			string $columnName,
			?string $qualifyWithAlias,
			SqlIdentifierQuoter $identifierQuoter,
			PlatformCapabilitiesInterface $platform
		): string {
			if ($qualifyWithAlias === null || !$platform->supportsQualifiedSetTarget()) {
				return $identifierQuoter->quoteIdentifier($columnName);
			} else {
				return $identifierQuoter->quoteIdentifier($qualifyWithAlias) . '.' . $identifierQuoter->quoteIdentifier($columnName);
			}
		}
	}
