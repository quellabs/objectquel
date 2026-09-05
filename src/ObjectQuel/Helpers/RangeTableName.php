<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;

	/**
	 * Resolves the physical table name for a range that is directly SQL-backed
	 * (an entity range or a plain-table range) — the two range kinds that can
	 * appear in a real FROM/JOIN clause, and the two write-verb targets
	 * (see objectquel-plain-table-range-plan.md). An entity range's table name
	 * comes from EntityStore metadata; a plain-table range already carries its
	 * own table name literally, with no metadata lookup at all.
	 */
	class RangeTableName {

		/**
		 * @param AstRangeDatabase|AstRangeTable $range
		 * @param EntityStore $entityStore
		 * @return string
		 */
		public static function resolve(AstRangeDatabase|AstRangeTable $range, EntityStore $entityStore): string {
			if ($range instanceof AstRangeTable) {
				return $range->getTableName();
			}

			return $entityStore->getMetadata($range->getEntityName())->tableName;
		}
	}
