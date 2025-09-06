<?php
	
	namespace Quellabs\ObjectQuel\Serialization\UrlBuilders;
	
	use Quellabs\Support\StringInflector;
	
	/**
	 * Builds URLs for JSON:API resources and relationships following the JSON:API specification.
	 * Handles URL construction for individual resources and their relationship endpoints.
	 */
	class JsonApiUrlBuilder implements UrlBuilderInterface {
		
		/** @var string The base URL for the API, stored without trailing slash */
		private string $baseUrl;
		
		/**
		 * Initialize the URL builder with a base URL.
		 * @param string $baseUrl The base URL for the API (trailing slash will be removed)
		 */
		public function __construct(string $baseUrl) {
			// Remove trailing slash to ensure consistent URL formatting
			$this->baseUrl = rtrim($baseUrl, '/');
		}
		
		/**
		 * Builds a URL for a specific resource.
		 * Follows JSON:API format: /entityType/id
		 * @param string $entityType The type of entity (e.g., 'users', 'posts')
		 * @param string $id The unique identifier for the resource
		 * @return string The complete URL for the resource
		 */
		public function buildResourceUrl(string $entityType, string $id): string {
			$entityTypePlural = StringInflector::pluralize($entityType);
			return "{$this->baseUrl}/{$entityTypePlural}/{$id}";
		}
		
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
		public function buildRelationshipUrls(string $entityType, string $id, string $relationshipName): array {
			// Get the base resource URL first
			$resourceUrl = $this->buildResourceUrl($entityType, $id);
			
			return [
				// URL for the relationship linkage (JSON:API relationships endpoint)
				'self'    => "{$resourceUrl}/relationships/{$relationshipName}",
				
				// URL for the actual related resource data
				'related' => "{$resourceUrl}/{$relationshipName}"
			];
		}
	}