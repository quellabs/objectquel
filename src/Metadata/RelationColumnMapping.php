<?php

	namespace Quellabs\ObjectQuel\Metadata;

	/**
	 * Ordered list of resolved column pairs for one owning relation (ManyToOne/OneToOne).
	 * This is the only column-mapping input relation code should consume — never the
	 * relation annotation's localColumn/referencedColumn or localColumns/referencedColumns
	 * directly — so there is exactly one code path regardless of which annotation form
	 * (singular or plural) a given relation was declared with.
	 */
	readonly class RelationColumnMapping {

		/** @var non-empty-list<RelationColumnPair> Ordered */
		public array $pairs;

		/**
		 * @param non-empty-list<RelationColumnPair> $pairs Ordered
		 */
		public function __construct(array $pairs) {
			if ($pairs === []) {
				throw new \InvalidArgumentException('RelationColumnMapping requires at least one column pair.');
			}

			$this->pairs = $pairs;
		}

		/**
		 * @return list<string> Local property names, in pair order
		 */
		public function localProperties(): array {
			return array_map(static fn(RelationColumnPair $pair) => $pair->localProperty, $this->pairs);
		}

		/**
		 * @return list<string> Local database column names, in pair order
		 */
		public function localColumns(): array {
			return array_map(static fn(RelationColumnPair $pair) => $pair->localColumn, $this->pairs);
		}

		/**
		 * @return list<string> Target property names, in pair order
		 */
		public function targetProperties(): array {
			return array_map(static fn(RelationColumnPair $pair) => $pair->targetProperty, $this->pairs);
		}

		/**
		 * @return list<string> Target database column names, in pair order
		 */
		public function targetColumns(): array {
			return array_map(static fn(RelationColumnPair $pair) => $pair->targetColumn, $this->pairs);
		}

		/**
		 * True when this relation spans more than one column.
		 * @return bool
		 */
		public function isComposite(): bool {
			return count($this->pairs) > 1;
		}
	}
