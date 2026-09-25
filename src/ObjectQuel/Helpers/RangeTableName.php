<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	
	/**
	 * Resolves the physical table name for an entity range's EntityStore
	 * metadata — the range kind that can appear in a real FROM/JOIN clause
	 * and as a write-verb target.
	 */
	class RangeTableName {
		
		/**
		 * @param AstRangeDatabase $range
		 * @param EntityStore $entityStore
		 * @return string
		 * @throws EntityResolutionException
		 */
		public static function resolve(AstRangeDatabase $range, EntityStore $entityStore): string {
			return $entityStore->getMetadata($range->getEntityName())->tableName;
		}
	}
