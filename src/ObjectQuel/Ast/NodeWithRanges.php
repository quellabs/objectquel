<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Structural interface for nodes that own a list of ranges (FROM/JOIN
	 * sources for a `retrieve`, or the single target range of a `replace`).
	 *
	 * Implemented by AstRetrieve (its full ranges list) and AstReplace (its
	 * one target range). Identifier-resolution walkers that need to match an
	 * identifier's root name against declared ranges — ResolveRootIdentifierType,
	 * ResolveIdentifierRange — use this interface instead of requiring
	 * AstRetrieve specifically, so `replace`'s WHERE clause and assignment
	 * values resolve through the exact same identifier-typing pipeline a
	 * `retrieve`'s WHERE clause does (see objectquel-replace-plan.md).
	 */
	interface NodeWithRanges extends AstInterface {

		/**
		 * Returns the ranges available for identifier resolution.
		 * @return AstRange[]
		 */
		public function getRanges(): array;
	}
