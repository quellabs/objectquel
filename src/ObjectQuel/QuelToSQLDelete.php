<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\WriteVerbIdentifierResolver;

	/**
	 * Compiles an AstDelete statement to dialect-correct DELETE SQL. Sibling
	 * to QuelToSQLReplace/QuelToSQLAppend — each QUEL statement kind gets
	 * its own compiler here.
	 *
	 * The target table is aliased with the QUEL range name — `DELETE FROM
	 * <table> as <alias> WHERE ...` — so the WHERE clause's `range.property`
	 * identifiers resolve to valid SQL via BuildSqlFromAst exactly as they
	 * do in a `retrieve`'s WHERE clause; unlike `replace`, there's no SET
	 * clause here, so no bare-vs-qualified column distinction to worry
	 * about at all.
	 *
	 * Like `replace`, `delete`'s WHERE clause only ever has one range to
	 * resolve against (see AstDelete's "single target range only" scope
	 * cut), so identifier resolution is the small, direct visitor sequence
	 * in WriteVerbIdentifierResolver — no QueryNormalizer/SemanticAnalyzer/
	 * QueryOptimizer, which exist for retrieve's JOIN/subquery/aggregate
	 * machinery that a single-range `delete` never needs.
	 */
	class QuelToSQLDelete {

		private EntityStore $entityStore;
		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLDelete constructor
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->entityStore = $entityStore;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Compiles a `delete <range> where ...` statement to SQL.
		 * @param AstDelete $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException
		 */
		public function convertToSQL(AstDelete $statement, array &$parameters): string {
			// The WHERE clause's identifiers need a resolved type/range
			// before they can compile to SQL.
			WriteVerbIdentifierResolver::resolve($statement, $this->entityStore);

			$range = $statement->getRange();
			$metadata = $this->entityStore->getMetadata($range->getEntityName());
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform);

			return sprintf(
				'DELETE FROM %s as %s WHERE %s',
				$this->identifierQuoter->quoteIdentifier($metadata->tableName),
				$this->identifierQuoter->quoteIdentifier($range->getName()),
				$builder->visitNodeAndReturnSQL($statement->getConditions())
			);
		}
	}
