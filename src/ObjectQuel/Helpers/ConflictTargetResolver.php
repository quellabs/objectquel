<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;

	/**
	 * Resolves an upsert's `append ... or replace (...) where <cond>` conflict
	 * target and validates it against a real declared unique/primary-key
	 * constraint — the one genuinely new piece of semantic work upsert needs
	 * (see objectquel-upsert-plan.md).
	 *
	 * `ON CONFLICT`/`ON DUPLICATE KEY UPDATE`/`MERGE` are only atomic because
	 * the database enforces the uniqueness itself during the single
	 * statement; without a real constraint backing the WHERE's equality
	 * columns, there is no atomic native form to compile to in any target
	 * dialect, and silently falling back to a check-then-branch would make
	 * the statement racy under concurrent writes. So an arbitrary,
	 * non-unique-backed predicate is rejected here at compile time rather
	 * than accepted and quietly weakened.
	 */
	class ConflictTargetResolver {

		/**
		 * Resolves `<cond>`'s equality columns and checks them against a
		 * declared unique or primary key constraint covering exactly those
		 * columns (order doesn't matter; the column *set* must match exactly
		 * — not a subset or superset).
		 * @param AstInterface $conditions The onConflict AstReplace's WHERE condition
		 * @param EntityMetadataRecord $metadata
		 * @return string[] The matched property names, in WHERE-clause order
		 * @throws SemanticException
		 */
		public static function resolve(AstInterface $conditions, EntityMetadataRecord $metadata): array {
			$properties = [];
			self::collectEqualityProperties($conditions, $properties, IdentifierType::EntityProperty);

			$propertySet = $properties;
			sort($propertySet);

			foreach (self::candidateConstraints($metadata) as $candidate) {
				sort($candidate);

				if ($candidate === $propertySet) {
					return $properties;
				}
			}

			throw new SemanticException(sprintf(
				"append ... or replace's WHERE clause (%s) doesn't match any declared unique or primary key constraint on '%s' — an upsert's conflict target must be backed by a real constraint the database enforces, or no dialect can compile it to a single atomic statement.",
				implode(', ', $properties),
				$metadata->className
			));
		}

		/**
		 * Plain-table variant: there is no entity metadata, so there is no
		 * declared unique/primary-key constraint to check the conflict target
		 * against — the same "no live-schema validation" policy every other
		 * plain-table-range check already follows (see
		 * objectquel-plain-table-range-plan.md). If the named columns aren't
		 * actually backed by a real constraint, the database rejects the
		 * compiled ON CONFLICT/ON DUPLICATE KEY UPDATE/MERGE statement itself
		 * at execution time — the same place an unknown column already
		 * surfaces its error for a plain-table range.
		 * @param AstInterface $conditions The onConflict AstReplace's WHERE condition
		 * @return string[] The matched column names, in WHERE-clause order
		 * @throws SemanticException
		 */
		public static function resolveForTable(AstInterface $conditions): array {
			$properties = [];
			self::collectEqualityProperties($conditions, $properties, IdentifierType::TableProperty);
			return $properties;
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
		 * isn't a fixed conflict-target set the compiler can resolve
		 * unambiguously, so it's rejected outright — the compiler doesn't
		 * guess which unique constraint is meant.
		 * @param AstInterface $node
		 * @param string[] $properties
		 * @return void
		 * @throws SemanticException
		 */
		private static function collectEqualityProperties(AstInterface $node, array &$properties, IdentifierType $propertyType): void {
			if ($node instanceof AstBinaryOperator && $node->getOperator() === 'AND') {
				self::collectEqualityProperties($node->getLeft(), $properties, $propertyType);
				self::collectEqualityProperties($node->getRight(), $properties, $propertyType);
				return;
			}

			if ($node instanceof AstExpression && $node->getOperator() === '=') {
				$property = self::extractProperty($node->getLeft(), $propertyType) ?? self::extractProperty($node->getRight(), $propertyType);

				if ($property !== null) {
					$properties[] = $property;
					return;
				}
			}

			throw new SemanticException(
				"append ... or replace's WHERE clause must be a conjunction ('and') of plain 'property = value' equality checks against the target range — the compiler doesn't guess which unique constraint is meant."
			);
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
		private static function extractProperty(AstInterface $node, IdentifierType $propertyType): ?string {
			if (!$node instanceof AstIdentifier) {
				return null;
			}

			$property = $node->getNext();

			if ($property === null || $property->hasNext()) {
				return null;
			}

			if ($property->getType() !== $propertyType) {
				return null;
			}

			return $property->getName();
		}
	}
