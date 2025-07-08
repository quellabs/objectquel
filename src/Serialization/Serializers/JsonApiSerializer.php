<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Serializers;
	
	use Quellabs\ObjectQuel\EntityManager;
	
	class JsonApiSerializer extends Serializer {
		private EntityManager $entityManager;
		
		/**
		 * JsonApiSerializer constructor
		 * @param EntityManager $entityManager
		 * @param string $serializationGroupName
		 */
		public function __construct(EntityManager $entityManager, string $serializationGroupName="") {
			$this->entityManager = $entityManager;
			parent::__construct($entityManager->getEntityStore(), $serializationGroupName);
		}
		
		/**
		 * Implementation of laravel's class_basename
		 * @param $class
		 * @return string
		 */
		protected function class_basename($class): string {
			$class = is_object($class) ? get_class($class) : $class;
			return basename(str_replace('\\', '/', $class));
		}
		
		/**
		 * Convert entity name to snake_case and remove Entity from the name if present
		 * @param string $entityName
		 * @return string
		 */
		protected function normalizeEntityName(string $entityName): string {
			$removedNamespace = $this->class_basename($entityName);
			return preg_replace('/Entity$/', '', $removedNamespace);
		}
		
		/**
		 * Collect all identifier values for the related entity
		 * @param object $entity
		 * @return array
		 */
		protected function getIdentifierValues(object $entity): array {
			$result = [];
			
			foreach ($this->entityStore->getIdentifierKeys($entity) as $key) {
				$result[] = $this->propertyHandler->get($entity, $key);
			}
			
			return $result;
		}
	
		/**
		 * Serializes the relationships of a given entity.
		 * @param object $entity The entity whose relationships are to be serialized.
		 * @param mixed $identifierValue The identifier value of the entity.
		 * @return array An array containing the serialized relationships.
		 */
		public function serializeRelationships(object $entity, mixed $identifierValue): array {
			// Get all one-to-many dependencies for the entity
			$relationships = array_merge($this->entityStore->getOneToManyDependencies($entity), $this->entityStore->getOneToOneDependencies($entity));

			// Process the relationships
			$result = [];
			
			foreach ($relationships as $property => $relationship) {
				// Convert the target entity class name to snake_case for consistent naming
				$relationshipEntityName = $this->normalizeEntityName($relationship->getTargetEntity());

				// Find all related entities based on the mapped relationship
				$relationshipEntities = $this->entityManager->findBy($relationship->getTargetEntity(), [$relationship->getMappedBy() => $identifierValue]);
				
				if (empty($relationshipEntities)) {
					continue;
				}

				// Process the relations
				$relationshipEntries = [];
				
				foreach ($relationshipEntities as $relationshipEntity) {
					// Create an entry for each related entity
					$relationshipEntries[] = [
						'type' => $relationshipEntityName,
						'id'   => implode("_", $this->getIdentifierValues($relationshipEntity))
					];
				}
				
				// Add the relationship data to the result
				$result[$this->snakeCase($property)] = [
					'data'  => $relationshipEntries,
					'links' => [
						'self'    => "https://example.com/products/1697/relationships/products_description",
						'related' => "https://example.com/products/1697/products_description",
					]
				];
			}
			
			return $result;
		}
		
		/**
		 * Extracts all values from the entity that are marked as Column.
		 * @param object $entity The entity from which the values must be extracted.
		 * @return array An array with property names as keys and their values.
		 */
		public function serialize(object $entity): array {
			$entityName = $this->normalizeEntityName(get_class($entity));
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierValue = $this->propertyHandler->get($entity, $identifierKeys[0]);
			
			$result = [
				'type'       => $entityName,
				'id'         => implode("_", $this->getIdentifierValues($entity)),
				'attributes' => parent::serialize($entity),
			];
			
			$relationships = $this->serializeRelationShips($entity, $identifierValue);
			
			if (!empty($relationships)) {
				$result['relationships'] = $relationships;
			}
			
			return [
				'data'    => $result,
			];
		}
	}