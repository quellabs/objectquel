<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;

	/** Quotes a SET-clause target column, qualifying it with the range alias only when the platform allows it. */
	class SetTargetColumnQuoter {

		/**
		 * @param string $columnName
		 * @param string|null $qualifyWithAlias The statement's own range alias, or null to always render bare
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
			}

			return $identifierQuoter->quoteIdentifier($qualifyWithAlias) . '.' . $identifierQuoter->quoteIdentifier($columnName);
		}
	}
