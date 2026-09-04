<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;

	/**
	 * Compiles an AstDestroy statement to one `DROP TABLE <name>` per target.
	 * Sibling to QuelToSQLRetrieve/QuelToSQLCreate.
	 *
	 * No `IF EXISTS` and no dialect branching — see DestroyExecutor, which
	 * runs the SQL this class produces, for why: an unknown name must fail
	 * loudly rather than silently no-op, and plain `DROP TABLE` is correct on
	 * every engine for both permanent and session-temp tables.
	 */
	class QuelToSQLDestroy {

		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * QuelToSQLDestroy constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compiles a `destroy Name {, Name}` statement to SQL — one
		 * `DROP TABLE` statement per name, in the order they were named.
		 * @param AstDestroy $statement
		 * @return string[]
		 */
		public function convertToSQL(AstDestroy $statement): array {
			return array_map(
				fn(string $name) => 'DROP TABLE ' . $this->identifierQuoter->quoteIdentifier($name),
				$statement->getNames()
			);
		}
	}
