<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Serializers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\Serialization\UrlBuilders\UrlBuilderInterface;
	
	/**
	 * This serializer transforms ObjectQuel entities into JSON:API format,
	 * handling both attributes and relationships according to the JSON:API
	 * specification (https://jsonapi.org/).
	 */
	class JsonApiSerializer extends Serializer {
		
		/** @var EntityManager Entity manager for database operations and entity metadata */
		private EntityManager $entityManager;
		
		/** @var UrlBuilderInterface URL builder for generating JSON:API compliant URLs */
		private UrlBuilderInterface $urlBuilder;
		
		/**
		 * JsonApiSerializer constructor
		 * @param EntityManager $entityManager For entity operations and metadata access
		 * @param UrlBuilderInterface $urlBuilder For building resource and relationship URLs
		 * @param string $serializationGroupName Optional group name for property filtering
		 */
		public function __construct(EntityManager $entityManager, UrlBuilderInterface $urlBuilder, string $serializationGroupName = "") {
			$this->entityManager = $entityManager;
			$this->urlBuilder = $urlBuilder;
			parent::__construct($entityManager->getEntityStore(), $serializationGroupName);
		}
		
		/**
		 * Re-implementation of laravel's class_basename helper function.
		 * @param object|string $class Fully qualified class name or object instance
		 * @return string The base class name without namespace
		 */
		protected function class_basename(object|string $class): string {
			$class = is_object($class) ? get_class($class) : $class;
			
			// Convert namespace separators to forward slashes, then get basename
			return basename(str_replace('\\', '/', $class));
		}
		
		/**
		 * Convert entity class name to JSON:API resource type format.
		 *
		 * Transforms a fully-qualified entity class name to camelCase and removes
		 * the "Entity" suffix if present. This creates consistent resource type
		 * names for the JSON:API specification.
		 *
		 * Examples:
		 * - App\Entity\UserEntity -> user
		 * - App\Model\BlogPost -> blogPost
		 *
		 * @param string $entityName Fully qualified entity class name
		 * @return string Normalized resource type name in camelCase
		 */
		protected function normalizeEntityName(string $entityName): string {
			// Remove namespace from class name
			$removedNamespace = $this->class_basename($entityName);
			
			// Remove "Entity" suffix if present (common naming convention)
			$withoutEntitySuffix = preg_replace('/Entity$/', '', $removedNamespace);
			
			// Convert to camelCase (first letter lowercase)
			return lcfirst($withoutEntitySuffix);
		}
		
		/**
		 * Collect all identifier values for the given entity.
		 * @param object $entity The entity to extract identifiers from
		 * @return array Array of identifier values in the order they're defined
		 */
		protected function getIdentifierValues(object $entity): array {
			$result = [];
			
			// Get all identifier property names from entity metadata
			// Use property handler to safely extract values (handles private/protected properties)
			foreach ($this->entityStore->getIdentifierKeys($entity) as $key) {
				$result[] = $this->propertyHandler->get($entity, $key);
			}
			
			return $result;
		}
		
		/**
		 * Serializes the relationships of a given entity into JSON:API format.
		 *
		 * Builds the "relationships" section of a JSON:API resource object,
		 * including both relationship data and links. Handles both one-to-many
		 * and one-to-one relationships.
		 *
		 * @param object $entity The entity whose relationships are to be serialized
		 * @param mixed $identifierValue The primary identifier value for relationship queries
		 * @return array JSON:API compliant relationships array
		 * @throws QuelException
		 */
		public function serializeRelationships(object $entity, mixed $identifierValue): array {
			// Get all relationship mappings from entity metadata
			// Merge one-to-many and one-to-one dependencies
			$relationships = array_merge(
				$this->entityStore->getOneToManyDependencies($entity),
				$this->entityStore->getOneToOneDependencies($entity)
			);
			
			$result = [];
			$entityName = $this->normalizeEntityName(get_class($entity));
			// Create composite ID string for URL generation
			$entityId = implode("_", $this->getIdentifierValues($entity));
			
			// Process each relationship mapping
			foreach ($relationships as $property => $relationship) {
				// Get the target entity's resource type name
				$relationshipEntityName = $this->normalizeEntityName($relationship->getTargetEntity());
				
				// Query for all related entities using the mapped relationship
				// Uses the inverse side property (mappedBy) to find related records
				$relationshipEntities = $this->entityManager->findBy(
					$relationship->getTargetEntity(),
					[$relationship->getMappedBy() => $identifierValue]
				);
				
				// Skip empty relationships to keep response clean
				if (empty($relationshipEntities)) {
					continue;
				}
				
				$relationshipEntries = [];
				
				// Build resource identifier objects for each related entity
				foreach ($relationshipEntities as $relationshipEntity) {
					$relationshipEntries[] = [
						'type' => $relationshipEntityName,
						'id'   => implode("_", $this->getIdentifierValues($relationshipEntity))
					];
				}
				
				// Build complete relationship object with data and links
				$result[$property] = [
					'data'  => $relationshipEntries,  // Resource identifier objects
					'links' => $this->urlBuilder->buildRelationshipUrls($entityName, $entityId, $property)
				];
			}
			
			return $result;
		}
		
		/**
		 * Serialize an entity into complete JSON:API resource object format.
		 *
		 * Creates a full JSON:API resource object with:
		 * - type: Resource type (normalized entity name)
		 * - id: Resource identifier (composite if necessary)
		 * - attributes: Entity properties (from parent serializer)
		 * - relationships: Related resources (if any exist)
		 * - links: Self-link for the resource
		 *
		 * @param object $entity The entity to be serialized
		 * @return array Complete JSON:API document with data wrapper
		 * @throws \InvalidArgumentException If entity has no identifier keys
		 */
		public function serialize(object $entity): array {
			$entityName = $this->normalizeEntityName(get_class($entity));
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			
			// Validate that entity has proper identification
			if (empty($identifierKeys)) {
				throw new \InvalidArgumentException("Entity " . get_class($entity) . " must have at least one identifier");
			}
			
			// Get primary identifier value for relationship queries
			$identifierValue = $this->propertyHandler->get($entity, $identifierKeys[0]);
			
			// Create composite ID string for resource identification
			$entityId = implode("_", $this->getIdentifierValues($entity));
			
			// Build the core resource object
			$result = [
				'type'       => $entityName,                 // JSON:API resource type
				'id'         => $entityId,                   // JSON:API resource identifier
				'attributes' => parent::serialize($entity),  // Entity properties
				'links'      => [
					'self' => $this->urlBuilder->buildResourceUrl($entityName, $entityId)
				]
			];
			
			// Add relationships if any exist
			$relationships = $this->serializeRelationships($entity, $identifierValue);
			if (!empty($relationships)) {
				$result['relationships'] = $relationships;
			}
			
			// Wrap in JSON:API document format
			return [
				'data' => $result,
			];
		}
	}