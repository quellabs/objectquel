<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;

	/**
	 * SQL names for routine variables and cursor rows in generated routine code.
	 * Generated names start with '_', which QUEL identifiers can't, so they never
	 * collide with source names or range aliases.
	 */
	class RoutineReferenceSql {

		/** PL/pgSQL label of the routine's top block; locals are qualified with it so they never resolve as columns */
		public const string POSTGRES_BLOCK_LABEL = '_routine';

		/**
		 * @param string $cursorName Cursor name from the source
		 * @return string Name of the record variable holding the cursor's current row
		 */
		public static function cursorRowVariable(string $cursorName): string {
			return "_row_{$cursorName}";
		}

		/**
		 * Renders a RoutineVariable or CursorRoot identifier as a SQL expression.
		 * @param AstIdentifier $identifier Root identifier typed as a routine reference
		 * @param SqlIdentifierQuoter $quoter Quoter for the target engine
		 * @param string $databaseType Target engine, as reported by PlatformCapabilitiesInterface::getDatabaseType()
		 * @return string
		 * @throws QuelException When the target engine has no routine lowering yet
		 */
		public static function render(AstIdentifier $identifier, SqlIdentifierQuoter $quoter, string $databaseType): string {
			if ($databaseType !== 'pgsql') {
				throw new QuelException("Routines can't be compiled for '{$databaseType}' yet.");
			}

			$field = $identifier->getNext();

			if ($identifier->getType() === IdentifierType::CursorRoot && $field !== null) {
				return $quoter->quoteIdentifier(self::cursorRowVariable($identifier->getName())) . '.' . $quoter->quoteIdentifier($field->getName());
			}

			if ($identifier->getType() !== IdentifierType::RoutineVariable) {
				throw new \LogicException("'{$identifier->getCompleteName()}' is not a routine variable or cursor field reference.");
			}

			return $quoter->quoteIdentifier(self::POSTGRES_BLOCK_LABEL) . '.' . $quoter->quoteIdentifier($identifier->getName());
		}
	}
