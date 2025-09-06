<?php
	
	namespace Quellabs\ObjectQuel\Serialization\UrlBuilders;
	
	/**
	 * Interface for building URLs used in API responses, particularly for JSON:API compliance.
	 */
	interface UrlBuilderInterface {
		
		/**
		 * Builds the canonical URL for a specific resource instance.
		 * @param string $entityType The type/collection name of the entity (e.g., 'users', 'posts')
		 * @param string $id The unique identifier of the specific resource instance
		 * @return string The complete URL pointing to the resource (e.g., '/api/users/123')
		 */
		public function buildResourceUrl(string $entityType, string $id): string;
		
		/**
		 * Builds relationship URLs for a resource according to JSON:API specification.
		 * Returns both 'self' and 'related' URLs for the relationship.
		 * @param string $entityType The type of the parent entity
		 * @param string $id The ID of the parent resource
		 * @param string $relationshipName The name of the relationship
		 * @return array{self: string, related: string} Associative array with 'self' and 'related' URLs
		 *               - 'self': URL to the relationship object itself (for fetching/updating linkage)
		 *               - 'related': URL to the related resource data
		 */
		public function buildRelationshipUrls(string $entityType, string $id, string $relationshipName): array;
	}