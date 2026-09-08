<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\Serialization\Serializers\Serializer;

	/**
	 * Normalizes bound-parameter (`:param`) assignment values against their
	 * target column's @Orm\Column-type normalizer, via the same
	 * Serializer::denormalizeValue() logic InsertPersister/UpdatePersister
	 * apply when persisting an entity — e.g. a \DateTime object bound to a
	 * datetime column becomes its "Y-m-d H:i:s" storage string, a PHP array
	 * bound to a json column becomes its encoded JSON string, and a backed
	 * enum instance becomes its scalar value.
	 *
	 * Without this, a write verb's raw-SQL assignment path hands the driver
	 * an unconverted PHP value for anything but plain scalars, silently
	 * diverging from persist()'s behavior for the same entity. Shared by
	 * every write verb whose AST carries AstAssignment nodes (`append`,
	 * `replace`) so this logic is written once instead of each write verb's
	 * compiler/executor reimplementing its own copy — the same reasoning
	 * AssignmentValidator documents for its own compile-time checks.
	 *
	 * Only rewrites genuine `:param` bindings — a QUEL literal written
	 * directly in the statement (true, 'foo', 123) is already SQL-ready as
	 * parsed and carries no PHP value to normalize.
	 *
	 * `delete` has no AstAssignment nodes at all (only a WHERE clause), so
	 * it never has a reason to call this — there is nothing for it to
	 * normalize.
	 */
	class AssignmentNormalizer {

		/**
		 * Normalizes every assignment in $assignments whose value is a bound
		 * parameter, in place on $parameters.
		 * @param AstAssignment[] $assignments One row's (or replace's whole) assignment list
		 * @param EntityMetadataRecord $metadata
		 * @param Serializer $serializer
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @param array<string, true> $normalizedParamNames Reference to a dedup
		 *        set shared across every call for one statement, so a parameter
		 *        reused across multiple rows/assignments (e.g. a multi-row
		 *        `append` sharing one literal) isn't denormalized twice —
		 *        several normalizers (e.g. DatetimeNormalizer) aren't
		 *        idempotent and would corrupt an already-denormalized value on
		 *        a second pass.
		 * @return void
		 */
		public static function normalize(
			array $assignments,
			EntityMetadataRecord $metadata,
			Serializer $serializer,
			array &$parameters,
			array &$normalizedParamNames
		): void {
			$columnAnnotations = $metadata->getAnnotationsOfType(Column::class);

			foreach ($assignments as $assignment) {
				$value = $assignment->getValue();

				if (!$value instanceof AstParameter) {
					continue;
				}

				$paramName = $value->getName();

				if (isset($normalizedParamNames[$paramName]) || !array_key_exists($paramName, $parameters)) {
					continue;
				}

				$annotation = $columnAnnotations[$assignment->getProperty()][0] ?? null;

				if ($annotation === null) {
					continue;
				}

				$parameters[$paramName] = $serializer->denormalizeValue($annotation, $parameters[$paramName]);
				$normalizedParamNames[$paramName] = true;
			}
		}
	}
