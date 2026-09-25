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
	 * Resolves and validates identifiers for single-range write statements
	 * (`replace`, `delete`, and upsert conflict clauses), sharing the retrieve
	 * identifier pipeline through NodeWithRanges.
	 *
	 * Bare properties are resolved after typing against the single range;
	 * ResolveUnqualifiedProperty assigns the rewritten node's final type, so
	 * no second typing pass is needed.
	 */
	class WriteVerbIdentifierResolver {

		/**
		 * Resolves and validates identifiers in a write statement.
		 * @param NodeWithRanges $statement
		 * @param EntityStore $entityStore
		 * @return void
		 */
		public static function resolve(NodeWithRanges $statement, EntityStore $entityStore): void {
			$ranges = $statement->getRanges();

			// Assign identifier types and ranges before resolving bare properties.
			$statement->accept(new ResolveRootIdentifierType($statement));
			$statement->accept(new ResolvePropertyType($entityStore));
			$statement->accept(new ResolveIdentifierRange($statement));

			// Resolve bare properties against this statement's ranges.
			$statement->accept(new ResolveUnqualifiedProperty($entityStore, $ranges));

			// Preserve the specific ambiguity error before the declared-range check.
			$statement->accept(new ValidateUnambiguousProperty($entityStore, $ranges));
			$statement->accept(new ValidateRangesDeclared());
			$statement->accept(new ValidateEntityPropertyExists($entityStore));
		}
	}
