<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;

	/**
	 * SQL names for routine variables and cursor rows in generated routine code.
	 * Generated names start with '_', which QUEL identifiers can't, so they never
	 * collide with source names or range aliases. Cursor field variables join
	 * cursor and field with '$', which QUEL identifiers can't contain either.
	 */
	class RoutineReferenceSql {

		/** PL/pgSQL label of the routine's top block; locals are qualified with it so they never resolve as columns */
		public const string POSTGRES_BLOCK_LABEL = '_routine';

		/** Prefix of MySQL/MariaDB locals and parameters, which would otherwise shadow same-named columns */
		private const string MYSQL_VARIABLE_PREFIX = '_v_';

		/**
		 * @param string $cursorName Cursor name from the source
		 * @return string Name of the PL/pgSQL record variable holding the cursor's current row
		 */
		public static function cursorRowVariable(string $cursorName): string {
			return "_row_{$cursorName}";
		}

		/**
		 * @param string $cursorName Cursor name from the source
		 * @param string $field Field name (target-list alias) of the cursor's retrieve
		 * @param string $databaseType 'sqlsrv', 'mysql' or 'mariadb'
		 * @return string Variable a field of the cursor's current row is fetched into
		 * @throws QuelException When the engine doesn't fetch into per-field variables
		 */
		public static function cursorFieldVariable(string $cursorName, string $field, string $databaseType): string {
			return match ($databaseType) {
				'sqlsrv' => "@_row_{$cursorName}\${$field}",
				'mysql', 'mariadb' => "_row_{$cursorName}\${$field}",
				default => throw new QuelException("Routines on '{$databaseType}' don't fetch cursor rows into per-field variables."),
			};
		}

		/**
		 * @param string $name Local or parameter name from the source
		 * @param SqlIdentifierQuoter $quoter Quoter for the target engine
		 * @param string $databaseType Target engine, as reported by PlatformCapabilitiesInterface::getDatabaseType()
		 * @return string The variable as referenced in an expression
		 * @throws QuelException When the target engine has no routine lowering
		 */
		public static function variable(string $name, SqlIdentifierQuoter $quoter, string $databaseType): string {
			return match ($databaseType) {
				'pgsql' => $quoter->quoteIdentifier(self::POSTGRES_BLOCK_LABEL) . '.' . $quoter->quoteIdentifier($name),
				'sqlsrv' => "@{$name}",
				'mysql', 'mariadb' => self::MYSQL_VARIABLE_PREFIX . $name,
				default => throw new QuelException("Routines can't be compiled for '{$databaseType}'."),
			};
		}

		/**
		 * Renders a RoutineVariable or CursorRoot identifier as a SQL expression.
		 * @param AstIdentifier $identifier Root identifier typed as a routine reference
		 * @param SqlIdentifierQuoter $quoter Quoter for the target engine
		 * @param string $databaseType Target engine, as reported by PlatformCapabilitiesInterface::getDatabaseType()
		 * @return string
		 * @throws QuelException When the target engine has no routine lowering
		 */
		public static function render(AstIdentifier $identifier, SqlIdentifierQuoter $quoter, string $databaseType): string {
			if (!in_array($databaseType, ['pgsql', 'sqlsrv', 'mysql', 'mariadb'], true)) {
				throw new QuelException("Routines can't be compiled for '{$databaseType}'.");
			}

			$field = $identifier->getNext();

			if ($identifier->getType() === IdentifierType::CursorRoot && $field !== null) {
				if ($databaseType === 'pgsql') {
					return $quoter->quoteIdentifier(self::cursorRowVariable($identifier->getName())) . '.' . $quoter->quoteIdentifier($field->getName());
				}

				return self::cursorFieldVariable($identifier->getName(), $field->getName(), $databaseType);
			}

			if ($identifier->getType() !== IdentifierType::RoutineVariable) {
				throw new \LogicException("'{$identifier->getCompleteName()}' is not a routine variable or cursor field reference.");
			}

			return self::variable($identifier->getName(), $quoter, $databaseType);
		}
	}
