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
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateUnambiguousProperty;

	/**
	 * Resolves identifier types/ranges across a single-range write-verb
	 * statement (`replace`, `delete`, and an `append ... or replace where
	 * ...` upsert's on-conflict clause) and validates them — the same
	 * identifier-resolution sequence a `retrieve`'s WHERE clause goes
	 * through, applied via the NodeWithRanges interface instead of requiring
	 * AstRetrieve specifically (see objectquel-replace-plan.md /
	 * objectquel-delete-plan.md). Shared so QuelToSQLReplace/QuelToSQLDelete/
	 * QuelToSQLUpsert don't each carry their own copy of it.
	 *
	 * ResolveUnqualifiedProperty runs after the initial typing pass so a bare
	 * column (e.g. `amount` instead of `a.amount`) resolves against the
	 * statement's single range, exactly like a retrieve's bare-property
	 * shorthand (see ResolveUnqualifiedProperty's own docblock). A second
	 * typing pass isn't needed afterward — unlike a retrieve, there's no via-
	 * relation expansion or macro rewriting in between that would need
	 * re-typing, and ResolveUnqualifiedProperty already sets the rewritten
	 * node's final type itself.
	 */
	class WriteVerbIdentifierResolver {

		/**
		 * @param NodeWithRanges $statement
		 * @param EntityStore $entityStore
		 * @return void
		 * @throws SemanticException
		 */
		public static function resolve(NodeWithRanges $statement, EntityStore $entityStore): void {
			$ranges = $statement->getRanges();

			$statement->accept(new ResolveRootIdentifierType($statement));
			$statement->accept(new ResolvePropertyType($entityStore));
			$statement->accept(new ResolveIdentifierRange($statement));
			$statement->accept(new ResolveUnqualifiedProperty($entityStore, $ranges));

			// Ambiguity check before the declared-range check, so a bare
			// property matching more than one range gets this validator's
			// specific message instead of ValidateRangesDeclared's generic
			// "undefined range reference" — mirrors SemanticAnalyzer::validate()'s
			// ordering for the retrieve pipeline. A single-range write-verb can
			// never actually be ambiguous today, but this keeps the two
			// pipelines consistent.
			$statement->accept(new ValidateUnambiguousProperty($entityStore, $ranges));
			$statement->accept(new ValidateRangesDeclared());
			$statement->accept(new ValidateEntityPropertyExists($entityStore));
		}
	}
