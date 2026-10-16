<?php

	namespace Quellabs\ObjectQuel\Metadata;

	/**
	 * One resolved (localProperty, localColumn) <-> (targetProperty, targetColumn)
	 * pair of an owning relation (ManyToOne/OneToOne), after normalizing either
	 * the singular or the plural annotation form into the same shape.
	 */
	readonly class RelationColumnPair {

		/**
		 * @param string $localProperty Property name on the owning entity
		 * @param string $localColumn Database column backing $localProperty
		 * @param string $targetProperty Property name on the target entity
		 * @param string $targetColumn Database column backing $targetProperty
		 */
		public function __construct(
			public string $localProperty,
			public string $localColumn,
			public string $targetProperty,
			public string $targetColumn,
		) {
		}
	}
