<?php

	namespace Quellabs\ObjectQuel\Metadata;

	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Resolves the discriminator column/value pair for a single-table-
	 * inheritance (STI) entity class, shared by InsertPersister,
	 * QuelToSQLAppend, and Planner\Helpers\InjectDiscriminatorCondition.
	 *
	 * `@Orm\DiscriminatorColumn` is meant to live once on the shared base
	 * entity and `@Orm\DiscriminatorValue` once per subclass — but
	 * AnnotationReader::getClassAnnotations() reads only the given class's
	 * own docblock, never an ancestor's (unlike property annotations,
	 * inherited for free via reflection). This resolver walks the PHP
	 * inheritance chain itself, collecting the nearest declaration of each
	 * annotation independently, so the base-declares-column/subclass-
	 * declares-value split actually works.
	 */
	class DiscriminatorInfoResolver {

		/**
		 * @param EntityStore $entityStore
		 * @param class-string $className
		 * @return array{column: non-empty-string, value: non-empty-string}|null Null when the class (and none of its ancestors) participates in STI
		 * @throws AnnotationReaderException If annotation metadata cannot be read
		 * @throws QuelException When only one of @Orm\DiscriminatorValue/@Orm\DiscriminatorColumn is found across the hierarchy, or either carries an empty value — a misconfigured entity, not a "not STI" situation
		 */
		public static function resolve(EntityStore $entityStore, string $className): ?array {
			$annotationReader = $entityStore->getAnnotationReader();
			$discriminatorValue = null;
			$discriminatorColumn = null;

			for ($class = $className; $class !== false; $class = get_parent_class($class)) {
				$classAnnotations = $annotationReader->getClassAnnotations($class);
				$discriminatorValue ??= $classAnnotations->getFirst(DiscriminatorValue::class);
				$discriminatorColumn ??= $classAnnotations->getFirst(DiscriminatorColumn::class);
			}

			// Narrow getFirst()'s mixed return to the annotation type or null.
			$discriminatorValue = $discriminatorValue instanceof DiscriminatorValue ? $discriminatorValue : null;
			$discriminatorColumn = $discriminatorColumn instanceof DiscriminatorColumn ? $discriminatorColumn : null;

			// Neither anywhere in the hierarchy — an ordinary, non-STI entity.
			if ($discriminatorValue === null && $discriminatorColumn === null) {
				return null;
			}

			// Exactly one found means the entity declared half of STI's
			// contract — fail loudly rather than silently return null.
			if ($discriminatorValue === null || $discriminatorColumn === null) {
				throw new QuelException(sprintf(
					'Entity "%s" declares only %s — single-table inheritance requires both @Orm\DiscriminatorValue (on the subclass) and @Orm\DiscriminatorColumn (on the shared base entity).',
					$className,
					$discriminatorValue !== null ? '@Orm\DiscriminatorValue' : '@Orm\DiscriminatorColumn'
				));
			}

			$value = $discriminatorValue->getValue();
			$columnName = $discriminatorColumn->getName();

			if ($value === '' || $columnName === '') {
				throw new QuelException(sprintf(
					'Entity "%s" has STI annotations but %s is empty. Check your @Orm\DiscriminatorValue and @Orm\DiscriminatorColumn definitions.',
					$className,
					$value === '' ? '@Orm\DiscriminatorValue' : '@Orm\DiscriminatorColumn'
				));
			}

			return ['column' => $columnName, 'value' => $value];
		}
	}
