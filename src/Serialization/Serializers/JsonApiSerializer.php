<?php
	
	namespace Quellabs\ObjectQuel\Serialization\Serializers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Serialization\UrlBuilders\UrlBuilderInterface;
	
	/**
	 * This serializer transforms ObjectQuel entities into JSON:API format,
	 * handling both attributes and relationships according to the JSON:API
	 * specification (https://jsonapi.org/).
	 */
	class JsonApiSerializer extends Serializer {
		
		/** @var EntityManager Entity manager for database operations */
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
		 * Collect all identifier values for the given entity.
		 * @param object $entity The entity to extract identifiers from
		 * @return array<int, string> Array of identifier values in the order they're defined
		 * @throws EntityResolutionException
		 */
		protected function getIdentifierValues(object $entity): array {
			return array_map(static fn(array $pair) => (string)$pair[1], $this->getIdentifierPairs($entity));
		}

		/**
		 * Collect all identifier (property name, value) pairs for the given entity, in
		 * metadata order, with each value kept as the entity's own int or string type
		 * (never stringified) — the shape buildResourceId() needs so a composite id's
		 * encoded JSON keeps numeric and string components distinct.
		 * @param object $entity The entity to extract identifiers from
		 * @return array<int, array{0: string, 1: int|string}> Ordered [propertyName, value] pairs
		 * @throws EntityResolutionException
		 */
		protected function getIdentifierPairs(object $entity): array {
			// Fetch metadata
			$metadata = $this->entityStore->getMetadata($entity);

			// Get all identifier property names from entity metadata
			// Use property handler to safely extract values (handles private/protected properties)
			$result = [];

			foreach ($metadata->identifierKeys as $key) {
				$value = $this->propertyHandler->get($entity, $key);

				if (!is_scalar($value) && !($value instanceof \Stringable)) {
					throw new \UnexpectedValueException(
						sprintf('Identifier "%s" on %s must be scalar or Stringable, got %s', $key, get_class($entity), get_debug_type($value))
					);
				}

				$result[] = [$key, is_int($value) ? $value : (string)$value];
			}

			return $result;
		}

		/**
		 * Builds this entity's JSON:API resource id. A single-key entity keeps its
		 * existing plain-value format unchanged. A composite key is instead encoded as
		 * 'c1.' followed by the unpadded base64url of a UTF-8 JSON array of its ordered
		 * [propertyName, value] pairs — numeric and string components stay distinct
		 * because they're JSON-typed, not stringified. This replaces the previous
		 * underscore-joined format, which could not reconstruct component boundaries
		 * (a value containing '_' was indistinguishable from two components) and
		 * collapsed every component to a string.
		 * @param object $entity
		 * @return string
		 * @throws EntityResolutionException
		 */
		protected function buildResourceId(object $entity): string {
			$pairs = $this->getIdentifierPairs($entity);

			if (count($pairs) === 1) {
				return (string)$pairs[0][1];
			}

			$json = json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			return 'c1.' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
		}
		
		/**
		 * Serializes the relationships of a given entity into JSON:API format.
		 *
		 * Builds the "relationships" section of a JSON:API resource object,
		 * including both relationship data and links. Handles both one-to-many
		 * and one-to-one relationships.
		 *
		 * @param object $entity The entity whose relationships are to be serialized
		 * @return array<string, array{
		 *      data: array<int, array{type: string, id: string}>,
		 *      links: array<string, string>
		 *  }>
		 * @throws QuelException|EntityResolutionException
		 */
		public function serializeRelationships(object $entity): array {
			// Fetch metadata
			$metadata = $this->entityStore->getMetadata($entity);

			// Get all relationship mappings from entity metadata
			// Merge InverseOf and owning-side OneToOne dependencies
			$relationships = array_merge(
				$metadata->getInverseOfDependencies(),
				$metadata->getOneToOneDependencies()
			);

			$result = [];
			$entityName = $this->entityStore->normalizeEntityClass(get_class($entity));
			$entityId = $this->buildResourceId($entity);

			// Process each relationship mapping
			foreach ($relationships as $property => $relationship) {
				// Fetch the target entity
				$targetEntity = $relationship->getTargetEntity();

				// Validate the class exists
				if (!class_exists($targetEntity)) {
					throw new \RuntimeException("Relationship target '{$targetEntity}' is not a loadable class");
				}

				// Get the target entity's resource type name
				$relationshipEntityName = $this->entityStore->normalizeEntityClass($targetEntity);

				// InverseOf: the FK lives on the target entity, so the resolved mapping is
				// looked up on the target's owning-side relation (relation()); each pair's
				// local property (on the target) is matched against this entity's own value
				// for that pair's target property — every component, not just the first.
				// Owning OneToOne: the FK lives on this entity, so the resolved mapping is
				// looked up here directly; each pair's target property (on the target entity)
				// is matched against this entity's own value for that pair's local property.
				if ($relationship instanceof InverseOf) {
					$targetMetadata = $this->entityStore->getMetadata($targetEntity);
					$mapping = $targetMetadata->getRelationColumnMapping($relationship->getRelation());

					if ($mapping === null) {
						throw new \RuntimeException(
							"InverseOf relation '{$property}' on '{$entityName}' has no resolved column mapping on '{$targetEntity}'."
						);
					}

					$criteria = [];

					foreach ($mapping->pairs as $pair) {
						$criteria[$pair->localProperty] = $this->propertyHandler->get($entity, $pair->targetProperty);
					}
				} else {
					$mapping = $metadata->getRelationColumnMapping($property);

					if ($mapping === null) {
						throw new \RuntimeException("OneToOne relation '{$property}' on '{$entityName}' has no resolved column mapping.");
					}

					$criteria = [];

					foreach ($mapping->pairs as $pair) {
						$criteria[$pair->targetProperty] = $this->propertyHandler->get($entity, $pair->localProperty);
					}
				}

				// Query for all related entities using the resolved criteria
				$relationshipEntities = $this->entityManager->findBy($targetEntity, $criteria);

				// Skip empty relationships to keep response clean
				if (empty($relationshipEntities)) {
					continue;
				}

				$relationshipEntries = [];

				// Build resource identifier objects for each related entity
				foreach ($relationshipEntities as $relationshipEntity) {
					$relationshipEntries[] = [
						'type' => $relationshipEntityName,
						'id'   => $this->buildResourceId($relationshipEntity)
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
		 * @return array{
		 *     data: array{
		 *         type: string,
		 *         id: string,
		 *         attributes: array<string, mixed>,
		 *         links: array{self: string},
		 *         relationships?: array<string, array{
		 *             data: array<int, array{
		 *                 type: string,
		 *                 id: string
		 *             }>,
		 *             links: array<string, string>
		 *         }>
		 *     }
		 * }
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function serialize(object $entity): array {
			$entityName = $this->entityStore->normalizeEntityClass(get_class($entity));
			$metadata = $this->entityStore->getMetadata($entityName);
			
			// Validate that entity has proper identification
			if (empty($metadata->identifierKeys)) {
				throw new \InvalidArgumentException("Entity " . get_class($entity) . " must have at least one identifier");
			}

			// Resource identifier: the plain identifier value for a single-key entity,
			// or the encoded composite id for a composite key — see buildResourceId().
			$entityId = $this->buildResourceId($entity);

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
			$relationships = $this->serializeRelationships($entity);
			if (!empty($relationships)) {
				$result['relationships'] = $relationships;
			}
			
			// Wrap in JSON:API document format
			return [
				'data' => $result,
			];
		}
	}