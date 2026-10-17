<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\Support\StringInflector;
	
	/**
	 * This class creates JSON:API controllers for entities that extend JsonApiController
	 * and delegate CRUD operations to base class methods.
	 */
	class PacCanvasControllerGenerator extends PacGenerator {
		
		/**
		 * Creates the complete JSON:API controller code for the entity
		 * @return string The complete generated PHP controller code as a string
		 * @throws \RuntimeException If entity metadata cannot be retrieved
		 */
		public function create(): string {
			$baseName = $this->extractBaseName();
			$controllerName = "Pac" . $baseName . 'Controller';
			$resourceType = strtolower($baseName);
			$routePath = StringInflector::pluralize(strtolower($baseName));
			$currentDateTime = date('Y-m-d H:i:s');
			$qualifiedClassName = $this->entityStore->normalizeEntityClass($this->entityName);

			$code = sprintf('<?php

    // Auto-generated WakePAC controller for %s
    // Generated on %s
    
    namespace App\Controllers;
    
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Request;
    use Quellabs\Canvas\Annotations\Route;
    use Quellabs\Canvas\Controllers\JsonApiController;
    
    class %s extends JsonApiController {
    
        /**
         * Get the entity class name for this controller
         * @return string Fully qualified entity class name
         */
        protected function getEntityClass(): string {
            return %s::class;
        }
    
        /**
         * Get the JSON:API resource type for this entity
         * @return string The resource type identifier used in JSON:API responses
         */
        protected function getResourceType(): string {
            return \'%s\';
        }

        __WRITABLE_FIELDS_PLACEHOLDER__
        /**
         * Get a single %s resource by ID
         * @param int $id The resource ID
         * @return JsonResponse JSON:API formatted response
         * @Route("/%s/{id}", methods={"GET"})
         */
        public function get(int $id): JsonResponse {
            return $this->getResource($id);
        }
    
        /**
         * Create a new %s resource
         * @param Request $request HTTP request containing JSON:API formatted data
         * @return JsonResponse JSON:API formatted response with created resource
         * @Route("/%s", methods={"POST"})
         */
        public function create(Request $request): JsonResponse {
            return $this->createResource($request);
        }
    
        /**
         * Update an existing %s resource
         * @param int $id The resource ID to update
         * @param Request $request HTTP request containing JSON:API formatted data
         * @return JsonResponse JSON:API formatted response with updated resource
         * @Route("/%s/{id}", methods={"PUT", "PATCH"})
         */
        public function update(int $id, Request $request): JsonResponse {
            return $this->updateResource($id, $request);
        }
    
        /**
         * Delete a %s resource
         * @param int $id The resource ID to delete
         * @return JsonResponse Empty response with 204 status code
         * @Route("/%s/{id}", methods={"DELETE"})
         */
        public function delete(int $id): JsonResponse {
            return $this->deleteResource($id);
        }
    }
',
				"\\" . $qualifiedClassName,
				$currentDateTime,
				$controllerName,
				"\\" . $qualifiedClassName,
				$resourceType,
				$baseName,
				$routePath,
				$baseName,
				$routePath,
				$baseName,
				$routePath,
				$baseName,
				$routePath
			);

			return str_replace(
				'__WRITABLE_FIELDS_PLACEHOLDER__',
				$this->buildWritableFieldsStub($baseName),
				$code
			);
		}

		/**
		 * Build the getWritableFields() stub, deliberately returning an empty
		 * array: which attributes a resource should accept from clients is a
		 * business decision the generator cannot make, so nothing is writable
		 * until the developer deliberately lists some. The non-identifier
		 * properties are listed in a comment purely as a starting point for that
		 * decision, not applied automatically.
		 * @param string $baseName Entity base name, used only in the docblock
		 * @return string The stub method declaration, indented to match the template
		 */
		private function buildWritableFieldsStub(string $baseName): string {
			$entityData = $this->prepareEntityData();
			$candidates = array_values(array_diff(array_keys($entityData['columns']), $entityData['identifiers']));
			$candidateList = empty($candidates) ? '(none found)' : implode(', ', $candidates);

			return sprintf('/**
         * Attributes a client may set when creating or updating a %s.
         * Nothing is writable until you list it here — available non-identifier
         * properties: %s
         * Override getWritableFieldsForCreate()/getWritableFieldsForUpdate()
         * instead if create and update need to accept different fields.
         * @return list<string>
         */
        protected function getWritableFields(): array {
            return [];
        }
    ', $baseName, $candidateList);
		}
	}