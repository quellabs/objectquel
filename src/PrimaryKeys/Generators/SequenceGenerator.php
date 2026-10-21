<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys\Generators;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyGeneratorInterface;
	
	/**
	 * SequenceGenerator class for generating primary keys using a sequence-like approach
	 */
	class SequenceGenerator implements PrimaryKeyGeneratorInterface {
		
		/**
		 * Generate a new primary key for the given entity
		 * @param EntityManager $em The EntityManager instance
		 * @param object $entity The entity object for which to generate a primary key
		 * @param string $property The primary key property to generate a value for — a composite key's components each get their own MAX(col)+1 read
		 * @return mixed The generated primary key value
		 * @throws QuelException|EntityResolutionException
		 */
		public function generate(EntityManager $em, object $entity, string $property): mixed {
			// Get the database connection from the EntityManager
			$connection = $em->getConnection();

			// Get the table name associated with the entity
			$metadata = $em->getEntityStore()->getMetadata($entity);

			// Get the column mapping for the entity
			$columnMap = $metadata->columnMap;

			// Get the actual database column name for the target primary key property
			$primaryKey = $columnMap[$property];

			// Quote identifiers for the connected engine — both are plain real
			// column/table names, so DatabaseAdapter::escapeIdentifier() (the same
			// mechanism the Persistence\* classes use for INSERT/UPDATE/DELETE) is
			// the right tool here.
			$quotedPrimaryKey = $connection->escapeIdentifier($primaryKey);
			$quotedTableName = $connection->escapeIdentifier($metadata->tableName);

			// Execute a SQL query to get the next sequence value
			// This query finds the maximum current value and adds 1
			$rs = $connection->execute("
				 SELECT
					COALESCE(MAX({$quotedPrimaryKey}), 0) + 1
				 FROM {$quotedTableName}
			  ");

			// execute() returns StatementInterface|null
			if ($rs === null) {
				throw new \RuntimeException("SequenceGenerator could not generate a sequence for table '{$metadata->tableName}'.");
			}
			
			// Fetch the single scalar result and return it
			return $rs->fetchColumn(0);
		}
	}