<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;

	/**
	 * Compiles an AstDestroy statement to one `DROP TABLE <name>` per target.
	 * Sibling to QuelToSQLRetrieve/QuelToSQLCreate.
	 *
	 * No dialect branching — plain `DROP TABLE` is correct on every engine
	 * for both permanent and session-temp tables. `IF EXISTS` is included
	 * only when the statement's `if exists` qualifier is present; by default
	 * a missing name must fail loudly, not silently no-op — see
	 * DestroyExecutor.
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
		 * Compiles a `destroy Name {, Name} [if exists]` statement to SQL —
		 * one `DROP TABLE` statement per name, in the order they were named.
		 * @param AstDestroy $statement
		 * @return string[]
		 */
		public function convertToSQL(AstDestroy $statement): array {
			$keyword = $statement->isIfExists() ? 'DROP TABLE IF EXISTS ' : 'DROP TABLE ';

			return array_map(
				fn(string $name) => $keyword . $this->identifierQuoter->quoteIdentifier($name),
				$statement->getNames()
			);
		}
	}
