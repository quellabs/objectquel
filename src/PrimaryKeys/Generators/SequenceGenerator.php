<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys\Generators;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyGeneratorInterface;
	
	/**
	 * SequenceGenerator class for generating primary keys using a sequence-like approach
	 */
	class SequenceGenerator implements PrimaryKeyGeneratorInterface {
		
		/**
		 * Generate a new primary key for the given entity
		 * @param EntityManager $em The EntityManager instance
		 * @param object $entity The entity object for which to generate a primary key
		 * @return mixed The generated primary key value
		 */
		public function generate(EntityManager $em, object $entity): mixed {
			// Get the database connection from the EntityManager
			$connection = $em->getConnection();
			
			// Get the table name associated with the entity
			$tableName = $em->getEntityStore()->getOwningTable($entity);
			
			// Get the identifier keys (primary key fields) of the entity
			$identifierKeys = $em->getEntityStore()->getIdentifierKeys($entity);
			
			// Get the column mapping for the entity
			$columnMap = $em->getEntityStore()->getColumnMap($entity);
			
			// Get the actual database column name for the primary key
			$primaryKey = $columnMap[$identifierKeys[0]];
			
			// Execute a SQL query to get the next sequence value
			// This query finds the maximum current value and adds 1
			return $connection->GetOne("
				 SELECT
					COALESCE(MAX(`{$primaryKey}`), 0) + 1
				 FROM `{$tableName}`
			  ");
		}
	}