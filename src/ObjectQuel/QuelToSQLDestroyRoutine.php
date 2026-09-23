<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;

	/**
	 * Compiles `destroy function name [if exists]`. The statement doesn't say whether the
	 * routine is a FUNCTION or a PROCEDURE, so the SQL drops whichever exists.
	 */
	class QuelToSQLDestroyRoutine {

		private SqlIdentifierQuoter $identifierQuoter;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * @param AstDestroyRoutine $statement
		 * @return list<string> Statements to run in order
		 * @throws QuelException When the engine has no stored routines
		 */
		public function convertToSQL(AstDestroyRoutine $statement): array {
			$name = $statement->getName();
			$ifExists = $statement->isIfExists() ? 'IF EXISTS ' : '';
			$quotedName = $this->identifierQuoter->quoteIdentifier($name);

			return match ($this->platform->getDatabaseType()) {
				'pgsql' => ["DROP ROUTINE {$ifExists}{$quotedName}"],

				// Functions and procedures share one namespace, so at most one of them exists.
				'sqlsrv' => [
					"IF OBJECT_ID(N'{$this->identifierQuoter->escapeStringLiteral($name)}', N'P') IS NOT NULL "
					. "DROP PROCEDURE {$quotedName} ELSE DROP FUNCTION {$ifExists}{$quotedName}"
				],

				// Separate namespaces: drop both. DestroyRoutineExecutor checks existence
				// first when `if exists` is absent.
				'mysql', 'mariadb' => [
					"DROP FUNCTION IF EXISTS {$quotedName}",
					"DROP PROCEDURE IF EXISTS {$quotedName}",
				],

				default => throw new QuelException("Routines can't be destroyed on '{$this->platform->getDatabaseType()}'.", 'routine_destruction_error'),
			};
		}
	}
