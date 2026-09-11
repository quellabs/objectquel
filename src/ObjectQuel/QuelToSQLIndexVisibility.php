<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;

	/**
	 * Compiles an AstHideIndex/AstShowIndex statement (`hide Name on Table` /
	 * `show Name on Table`) to dialect-correct DDL. Sibling to
	 * QuelToSQLCreateIndex/QuelToSQLDestroyIndex.
	 *
	 * Both statements compile to the exact same shape — a single `ALTER
	 * TABLE ... ALTER INDEX ... <keyword>` statement — differing only in
	 * which of PlatformCapabilities::getIndexVisibilityKeywords()' two
	 * keywords is used, so one class handles both rather than duplicating
	 * the identifier-quoting/platform-guard plumbing across two near-empty
	 * files (same reasoning QuelToSQLAlter gives for handling several
	 * sub-operation kinds in one class).
	 *
	 * Only MySQL 8.0+ and MariaDB 10.6+ support invisible indexes at all
	 * (see PlatformCapabilities::supportsIndexHiding()) — every other
	 * dialect is rejected loudly here rather than silently emitting nothing,
	 * the same 'unsupported' treatment QuelToSQLAlter gives retype/PK/FK
	 * changes SQLite can't represent.
	 */
	class QuelToSQLIndexVisibility {

		private SqlIdentifierQuoter $identifierQuoter;

		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLIndexVisibility constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Compiles a `hide Name on Table` statement to SQL.
		 * @param AstHideIndex $statement
		 * @return string
		 * @throws QuelException If the connected engine doesn't support invisible indexes
		 */
		public function convertHideToSQL(AstHideIndex $statement): string {
			return $this->compile($statement->getIndexName(), $statement->getTableName(), 'hidden');
		}

		/**
		 * Compiles a `show Name on Table` statement to SQL.
		 * @param AstShowIndex $statement
		 * @return string
		 * @throws QuelException If the connected engine doesn't support invisible indexes
		 */
		public function convertShowToSQL(AstShowIndex $statement): string {
			return $this->compile($statement->getIndexName(), $statement->getTableName(), 'visible');
		}

		/**
		 * @param string $indexName
		 * @param string $tableName
		 * @param 'hidden'|'visible' $keywordKey Selects which of
		 *        getIndexVisibilityKeywords()'s two entries to render
		 * @return string
		 * @throws QuelException If the connected engine doesn't support invisible indexes
		 */
		private function compile(string $indexName, string $tableName, string $keywordKey): string {
			$this->assertIndexHidingSupported();

			$keyword = $this->platform->getIndexVisibilityKeywords()[$keywordKey];

			return sprintf(
				'ALTER TABLE %s ALTER INDEX %s %s',
				$this->identifierQuoter->quoteIdentifier($tableName),
				$this->identifierQuoter->quoteIdentifier($indexName),
				$keyword
			);
		}

		/**
		 * @return void
		 * @throws QuelException If the connected engine doesn't support invisible indexes
		 */
		private function assertIndexHidingSupported(): void {
			if (!$this->platform->supportsIndexHiding()) {
				throw new QuelException(
					"Cannot change index visibility: only MySQL 8.0+ and MariaDB 10.6+ support hiding an index from the query optimizer",
					'index_visibility_unsupported'
				);
			}
		}
	}
