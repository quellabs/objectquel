<?php

	namespace Quellabs\ObjectQuel\Metadata;

	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * One resolved @Orm\ForeignKey constraint declared on an entity — property
	 * level (always exactly one column pair) or class level (one or more pairs,
	 * for a constraint spanning more than one column). Its matched action
	 * annotation, when one was declared, is folded in here as onDelete/onUpdate,
	 * since matching the two is a one-time concern for EntityMetadataBuilder,
	 * not something every downstream consumer should redo.
	 *
	 * Deliberately distinct from RelationColumnMapping (ManyToOne/OneToOne
	 * relations) and from DatabaseAdapter\ForeignKeyDefinition (the fully
	 * resolved, real-table-and-column shape introspection and diffing use):
	 * this is the pre-resolution, annotation-level declaration — the target
	 * side is still a property name on another entity, not yet a column name,
	 * since resolving it requires that entity's own metadata, looked up lazily
	 * by downstream consumers (see ForeignKeyComparator) rather than here.
	 */
	readonly class ForeignKeyDeclaration {

		/**
		 * @param non-empty-list<string> $localProperties Local property names, in constraint order
		 * @param non-empty-list<string> $localColumns Local database column names, same order as $localProperties
		 * @param class-string $targetEntity Fully qualified target entity class name
		 * @param non-empty-list<string> $targetProperties Target entity property names, same order as $localProperties
		 * @param string $onDelete ON DELETE rule, e.g. 'CASCADE', 'RESTRICT', 'NO ACTION'
		 * @param string $onUpdate ON UPDATE rule, e.g. 'CASCADE', 'RESTRICT', 'NO ACTION'
		 */
		public function __construct(
			public array $localProperties,
			public array $localColumns,
			public string $targetEntity,
			public array $targetProperties,
			public string $onDelete,
			public string $onUpdate,
		) {
			if ($localProperties === [] || $localColumns === [] || $targetProperties === []) {
				throw new \InvalidArgumentException('ForeignKeyDeclaration requires at least one column pair.');
			}

			if (count($localProperties) !== count($localColumns) || count($localProperties) !== count($targetProperties)) {
				throw new \InvalidArgumentException('ForeignKeyDeclaration requires localProperties, localColumns, and targetProperties to have the same length.');
			}
		}

		/**
		 * True when this constraint spans more than one column.
		 * @return bool
		 */
		public function isComposite(): bool {
			return count($this->localColumns) > 1;
		}

		/**
		 * Builds a declaration from a resolved ForeignKey annotation's column
		 * lists plus its matched action annotation, if one was declared —
		 * applying the safe defaults when $action is null.
		 * @param list<string> $localProperties Non-empty; enforced by the constructor
		 * @param list<string> $localColumns Non-empty; enforced by the constructor
		 * @param class-string $targetEntity
		 * @param list<string> $targetProperties Non-empty; enforced by the constructor
		 * @param ForeignKeyAction|null $action
		 * @return self
		 */
		public static function fromAnnotations(
			array $localProperties,
			array $localColumns,
			string $targetEntity,
			array $targetProperties,
			?ForeignKeyAction $action
		): self {
			if ($localProperties === [] || $localColumns === [] || $targetProperties === []) {
				throw new \InvalidArgumentException('ForeignKeyDeclaration requires at least one column pair.');
			}

			return new self(
				localProperties: $localProperties,
				localColumns: $localColumns,
				targetEntity: $targetEntity,
				targetProperties: $targetProperties,
				onDelete: $action?->getOnDelete() ?? 'RESTRICT',
				onUpdate: $action?->getOnUpdate() ?? 'NO ACTION',
			);
		}
	}
