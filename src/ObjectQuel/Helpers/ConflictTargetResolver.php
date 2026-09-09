<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;

	/**
	 * Determines whether an upsert's `append ... or replace (...) where <cond>`
	 * WHERE clause's equality columns exactly match a declared unique or
	 * primary-key constraint, making the dialect-native atomic upsert form
	 * (`ON CONFLICT`/`ON DUPLICATE KEY UPDATE`/`MERGE`) compilable.
	 *
	 * Returns null (not an exception) when they don't — that's not an
	 * error, it's the signal `QuelToSQLUpsert` uses to fall back to a plain
	 * `UPDATE`-then-`INSERT`-if-unmatched instead (see
	 * QuelToSQLUpsert::compileNonAtomicFallback()). `ON CONFLICT`/`ON
	 * DUPLICATE KEY UPDATE`/`MERGE` are only atomic because the database
	 * enforces the uniqueness itself during the single statement; without a
	 * real constraint backing the WHERE's equality columns, there is no
	 * atomic native form to compile to in any target dialect, so the
	 * fallback path takes over instead.
	 */
	class ConflictTargetResolver {

		/**
		 * Resolves `<cond>`'s equality columns and checks them against a
		 * declared unique or primary key constraint covering exactly those
		 * columns (order doesn't matter; the column *set* must match exactly
		 * — not a subset or superset). Returns null when the WHERE clause
		 * isn't a plain conjunction of equality checks at all, or when it is
		 * but doesn't match any declared constraint.
		 * @param AstInterface $conditions The onConflict AstReplace's WHERE condition
		 * @param EntityMetadataRecord $metadata
		 * @return string[]|null The matched property names, in WHERE-clause order, or null
		 */
		public static function tryResolve(AstInterface $conditions, EntityMetadataRecord $metadata): ?array {
			$properties = [];

			if (!self::tryCollectEqualityProperties($conditions, $properties)) {
				return null;
			}

			$propertySet = $properties;
			sort($propertySet);

			foreach (self::candidateConstraints($metadata) as $candidate) {
				sort($candidate);

				if ($candidate === $propertySet) {
					return $properties;
				}
			}

			return null;
		}

		/**
		 * Every candidate unique constraint's property-name set: the primary
		 * key, plus every declared @Orm\UniqueIndex.
		 * @param EntityMetadataRecord $metadata
		 * @return array<int, string[]>
		 */
		private static function candidateConstraints(EntityMetadataRecord $metadata): array {
			$candidates = [$metadata->identifierKeys];

			foreach ($metadata->indexes as $index) {
				if ($index instanceof UniqueIndex) {
					$candidates[] = $index->getColumns();
				}
			}

			return $candidates;
		}

		/**
		 * Walks a conjunction (`AND`-only) of `property = value` equality
		 * checks, collecting the left-hand property names. Anything else
		 * (`OR`, a non-`=` comparison, a function call, a value-vs-value
		 * comparison with no property on either side) means the predicate
		 * isn't a fixed conflict-target set the compiler can resolve to an
		 * atomic form — that's not an error, it just means the WHERE clause
		 * isn't eligible for the atomic path (see this class's docblock).
		 * @param AstInterface $node
		 * @param string[] $properties
		 * @return bool Whether $node (and everything under it) decomposed into equalities
		 */
		private static function tryCollectEqualityProperties(AstInterface $node, array &$properties): bool {
			if ($node instanceof AstBinaryOperator && $node->getOperator() === 'AND') {
				return self::tryCollectEqualityProperties($node->getLeft(), $properties)
					&& self::tryCollectEqualityProperties($node->getRight(), $properties);
			}

			if ($node instanceof AstExpression && $node->getOperator() === '=') {
				$property = self::extractProperty($node->getLeft()) ?? self::extractProperty($node->getRight());

				if ($property !== null) {
					$properties[] = $property;
					return true;
				}
			}

			return false;
		}

		/**
		 * Returns the property name when $node is the root of a two-segment
		 * identifier chain naming a direct, leaf property on the target range
		 * (e.g. "u.email" — $node is "u", its single next segment is
		 * "email") — not a deeper relation/JSON path, which isn't real
		 * column equality. getLeft()/getRight() on a comparison always
		 * return the *root* of whatever identifier chain was parsed (see
		 * ArithmeticExpression::parsePrimaryExpression()), never the leaf
		 * property node directly, so the leaf is read via getNext().
		 * @param AstInterface $node
		 * @return string|null
		 */
		private static function extractProperty(AstInterface $node): ?string {
			if (!$node instanceof AstIdentifier) {
				return null;
			}

			$property = $node->getNext();

			if ($property === null || $property->hasNext()) {
				return null;
			}

			if ($property->getType() !== IdentifierType::EntityProperty) {
				return null;
			}

			return $property->getName();
		}
	}
