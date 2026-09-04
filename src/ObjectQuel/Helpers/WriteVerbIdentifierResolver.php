<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeWithRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveIdentifierRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRootIdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateEntityPropertyExists;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRangesDeclared;

	/**
	 * Resolves identifier types/ranges across a single-range write-verb
	 * statement (`replace`, `delete`) and validates them — the same fixed
	 * five-visitor sequence a `retrieve`'s WHERE clause goes through,
	 * applied via the NodeWithRanges interface instead of requiring
	 * AstRetrieve specifically (see objectquel-replace-plan.md /
	 * objectquel-delete-plan.md). Shared so QuelToSQLReplace/
	 * QuelToSQLDelete don't each carry their own copy of it.
	 */
	class WriteVerbIdentifierResolver {

		/**
		 * @param NodeWithRanges $statement
		 * @param EntityStore $entityStore
		 * @return void
		 * @throws SemanticException
		 */
		public static function resolve(NodeWithRanges $statement, EntityStore $entityStore): void {
			$statement->accept(new ResolveRootIdentifierType($statement));
			$statement->accept(new ResolvePropertyType($entityStore));
			$statement->accept(new ResolveIdentifierRange($statement));
			$statement->accept(new ValidateRangesDeclared());
			$statement->accept(new ValidateEntityPropertyExists($entityStore));
		}
	}
