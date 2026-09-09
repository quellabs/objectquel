<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveIdentifierRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRootIdentifierType;

	/**
	 * Walks an ObjectQuel AST and assigns IdentifierType values to every
	 * identifier node, ahead of QueryNormalizer's own transformation pipeline.
	 *
	 * This does no semantic checking — it only flags the type based on AST
	 * hierarchy (which kind of range an identifier chain resolves against).
	 */
	class IdentifierTypeResolver {

		private EntityStore $entityStore;

		/**
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}

		/**
		 * Recursively resolves identifier types for the given query and every
		 * nested subquery range, so inner queries are fully resolved before
		 * outer query processing.
		 * @param AstRetrieve $retrieve
		 * @return void Modifies the AST in-place
		 * @throws EntityResolutionException
		 */
		public function resolve(AstRetrieve $retrieve): void {
			// First, recursively set types all nested queries in temporary ranges
			// This ensures inner queries are fully resolved before outer query processing
			foreach ($retrieve->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseSubquery) {
					$this->resolve($range->getQuery());
				}
			}

			// Then set types on current query
			$retrieve->accept(new ResolveRootIdentifierType($retrieve));
			$retrieve->accept(new ResolvePropertyType($this->entityStore));
			$retrieve->accept(new ResolveIdentifierRange($retrieve));
		}
	}
