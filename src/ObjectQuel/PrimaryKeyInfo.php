<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	final class PrimaryKeyInfo {
		public function __construct(
			public readonly AstRange $range,
			public readonly string   $entityName,
			public readonly string   $primaryKey,
		) {
		}
		
		/**
		 * Retrieves the primary key of the main range from an AstRetrieve object.
		 * @param AstRetrieve $astRetrieve A reference to the AstRetrieve object representing the query
		 * @param EntityStore $entityStore
		 * @return PrimaryKeyInfo
		 * @throws EntityResolutionException|\LogicException
		 */
		public static function fromRetrieve(AstRetrieve $astRetrieve, EntityStore $entityStore): self {
			foreach ($astRetrieve->getRanges() as $range) {
				// Only accept database ranges
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Continue if the range contains a join property
				if ($range->getJoinProperty() !== null) {
					continue;
				}
				
				// Get the associated primary key if the range doesn't have a join property
				$entityName = $range->getEntityName();
				$primaryKey = $entityStore->getMetadata($entityName)->getPrimaryKey();
				
				// Continue if there is no primary key
				if ($primaryKey === null) {
					continue;
				}
				
				// Return the range name, entity name, and the primary key of the entity
				return new PrimaryKeyInfo($range, $entityName, $primaryKey);
			}
			
			// Return null if no range without a join property is found
			// This should never happen in practice, as such a query cannot be created
			throw new \LogicException("Malformed query: no primary range found in query");
		}
	}