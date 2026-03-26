<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\Support\StringInflector;
	
	/**
	 * This class creates JavaScript abstractions for entities that can be used
	 * with the WakaPAC framework for reactive data binding and component management.
	 *
	 * HTTP transport uses the WakaSync plugin (this._http), which is injected by
	 * wakaPAC.use(wakaSync) before any component is created. Requests are fire-and-
	 * forget: each method stores its request ID and results are delivered to msgProc()
	 * as MSG_HTTP_SUCCESS / MSG_HTTP_ERROR / MSG_HTTP_ABORT messages.
	 *
	 * The server speaks plain JSON: requests send a flat payload object and responses
	 * are flat objects of the form { id, ...attributes }. Error responses are expected
	 * to be of the form { error: '...' }.
	 */
	class PacJSGenerator extends PacGenerator {
		
		/**
		 * Creates the complete JavaScript WakaPAC abstraction code for the entity
		 * @return string The complete generated JavaScript code as a string
		 * @throws \Exception If the entity does not exist in the entity store
		 */
		public function create(): string {
			$baseName = $this->extractBaseName();
			$entityData = $this->prepareEntityData($this->entityStore);
			$codeComponents = $this->generateCodeComponents($entityData);
			
			return $this->buildJavaScriptCode($baseName, $codeComponents);
		}
		
		/**
		 * Generates all JavaScript code components from entity metadata
		 * @param array $entityData Entity metadata from prepareEntityData()
		 * @return array Associative array containing code components
		 */
		private function generateCodeComponents(array $entityData): array {
			$properties = [];
			$reset = [];
			$changes = [];
			$assignAfterLoad = [];
			$assignAfterSave = [];
			$lastKey = array_key_last($entityData['columns']);
			
			// Process columns
			foreach ($entityData['columns'] as $property => $column) {
				$hasDefault = $entityData['columnAnnotations'][$property]->hasDefault();
				$defaultValue = $entityData['columnAnnotations'][$property]->getDefault();
				
				$assignAfterLoad[] = "this.{$property} = data.{$property} ?? null;";
				$assignAfterSave[] = "if (typeof data.{$property} !== 'undefined') {";
				$assignAfterSave[] = "    this.{$property} = data.{$property};";
				$assignAfterSave[] = "}";
				
				if (in_array($property, $entityData['identifiers']) || !$hasDefault) {
					$properties[] = "{$property}: null";
					$reset[] = "this.{$property} = null;";
				} else {
					$formattedValue = TypeMapper::formatValue($defaultValue);
					$properties[] = "{$property}: " . $formattedValue;
					$reset[] = "this.{$property} = " . $formattedValue . ";";
				}
				
				if ($lastKey == $property) {
					$changes[] = "if (this.{$property} !== this._originalData.{$property}) {";
					$changes[] = "    return true;";
					$changes[] = "}";
					$changes[] = "return false;";
				} else {
					$changes[] = "if (this.{$property} !== this._originalData.{$property}) {";
					$changes[] = "    return true;";
					$changes[] = "}";
				}
			}
			
			// Add relationship properties
			foreach ($entityData['relationships'] as $property) {
				$properties[] = "{$property}: []";
				$reset[] = "this.{$property} = [];";
			}
			
			return [
				'properties'      => $properties,
				'reset'           => $reset,
				'changes'         => $changes,
				'toPayload'       => $this->generateSerializeStatements($entityData['columns'], $entityData['columnAnnotations']),
				'assignAfterLoad' => $assignAfterLoad,
				'assignAfterSave' => $assignAfterSave,
			];
		}
		
		/**
		 * Builds the complete JavaScript abstraction code from all components.
		 *
		 * Architecture notes (current WakaPAC / WakaSync API):
		 *
		 *   - HTTP transport is provided by the WakaSync plugin, which injects
		 *     this._http into every abstraction via onComponentCreated().
		 *     Prerequisite: wakaPAC.use(wakaSync) must be called before any
		 *     wakaPAC('#…', …) call.
		 *
		 *   - this._http.get/post/put/delete() are fire-and-forget. Each returns
		 *     a numeric request ID that is stored on _pendingLoad / _pendingSave /
		 *     _pendingDelete so msgProc() can correlate the response.
		 *
		 *   - Responses arrive as PAC messages in msgProc():
		 *       wakaPAC.MSG_HTTP_SUCCESS  event.detail.data  — parsed response body
		 *       wakaPAC.MSG_HTTP_ERROR    event.lParam       — HTTP status code,
		 *                                event.detail.error  — Error object
		 *       wakaPAC.MSG_HTTP_ABORT                       — request was cancelled
		 *
		 * @param string $baseName The clean entity name (e.g., 'User', 'Product')
		 * @param array $components All generated code components from generateCodeComponents()
		 * @return string The complete, formatted JavaScript abstraction code
		 */
		private function buildJavaScriptCode(string $baseName, array $components): string {
			$baseNameLower = strtolower($baseName);
			$baseNamePlural = StringInflector::pluralize($baseNameLower);
			
			return sprintf(trim("
// Auto-generated WakaPAC abstraction for {$this->entityName}
// Generated on " . date('Y-m-d H:i:s') . "
// Usage:
//   wakaPAC.use(wakaSync);          // must come first — injects this._http
//   wakaPAC('#my-{$baseNameLower}', {$baseName}Abstraction);

const {$baseName}Abstraction = {
%s,

    /**
     * Base URL for AJAX requests
     */
    _baseUrl: '/{$baseNamePlural}',

    /**
     * Pending request IDs for response correlation in msgProc().
     * Initialised to null; set to the numeric ID returned by this._http.*()
     * and cleared back to null once the response has been handled.
     */
    _pendingLoad:   null,
    _pendingSave:   null,
    _pendingDelete: null,

    /**
     * Add computed properties here
     */
    computed: {
    },

    /**
     * Reset all properties to their default values
     */
    reset() {
%s
    },

    /**
     * Check if any properties have changed from original data
     */
    hasChanges() {
        if (!this._originalData) return true;
%s
    },

    /**
     * Serializes the model to a plain JSON payload for POST / PUT requests.
     * @returns {Object}
     */
    toPayload() {
        const payload = {};

    %s

        return payload;
    },

    /**
     * Loads the entity from the server (GET /{$baseNamePlural}/{id}).
     * The response is processed asynchronously in msgProc() when
     * MSG_HTTP_SUCCESS arrives with wParam === this._pendingLoad.
     * @param {string|number} id
     */
    load(id) {
        this._pendingLoad = this._http.get(
            `\${this._baseUrl}/\${id}`,
            {
                headers: {
                    'Accept':           'application/json',
                    'X-Requested-With': 'WakaPAC',
                    'X-PAC-Version':    '1.0'
                }
            }
        );
    },

    /**
     * Saves the entity to the server (POST for new, PUT for existing).
     * The response is processed asynchronously in msgProc() when
     * MSG_HTTP_SUCCESS arrives with wParam === this._pendingSave.
     */
    save() {
        const url  = this.id ? `\${this._baseUrl}/\${this.id}` : this._baseUrl;
        const body = JSON.stringify(this.toPayload());
        const opts = {
            headers: {
                'Accept':           'application/json',
                'Content-Type':     'application/json',
                'X-Requested-With': 'WakaPAC',
                'X-PAC-Version':    '1.0'
            }
        };

        this._pendingSave = this.id
            ? this._http.put(url,  body, opts)
            : this._http.post(url, body, opts);
    },

    /**
     * Deletes the entity from the server (DELETE /{$baseNamePlural}/{id}).
     * The response is processed asynchronously in msgProc() when
     * MSG_HTTP_SUCCESS arrives with wParam === this._pendingDelete.
     */
    delete() {
        if (!this.id) {
            throw new Error('Cannot delete {$baseName} without an ID');
        }

        this._pendingDelete = this._http.delete(
            `\${this._baseUrl}/\${this.id}`,
            {
                headers: {
                    'Accept':           'application/json',
                    'X-Requested-With': 'WakaPAC',
                    'X-PAC-Version':    '1.0'
                }
            }
        );
    },

    /**
     * Central message handler (Win32-style WndProc).
     *
     * Handles HTTP responses delivered by WakaSync:
     *
     *   MSG_HTTP_SUCCESS  wParam = requestId, event.detail.data = parsed body
     *   MSG_HTTP_ERROR    wParam = requestId, lParam = HTTP status code,
     *                     event.detail.error = Error object
     *   MSG_HTTP_ABORT    wParam = requestId
     *
     * @param {Object} event - PAC message event
     */
    msgProc(event) {
        switch (event.message) {

            case wakaPAC.MSG_HTTP_SUCCESS: {
                const data = event.detail.data;
                const rid  = event.wParam;

                if (rid === this._pendingLoad) {
                    this._pendingLoad = null;
                    this._onLoadSuccess(data);

                } else if (rid === this._pendingSave) {
                    this._pendingSave = null;
                    this._onSaveSuccess(data);

                } else if (rid === this._pendingDelete) {
                    this._pendingDelete = null;
                    this._onDeleteSuccess();
                }

                return true;
            }

            case wakaPAC.MSG_HTTP_ERROR: {
                const rid = event.wParam;

                if (rid === this._pendingLoad)   this._pendingLoad   = null;
                if (rid === this._pendingSave)    this._pendingSave   = null;
                if (rid === this._pendingDelete)  this._pendingDelete = null;

                this._handleError(event.detail.error, event.lParam);
                return true;
            }

            case wakaPAC.MSG_HTTP_ABORT: {
                const rid = event.wParam;

                if (rid === this._pendingLoad)   this._pendingLoad   = null;
                if (rid === this._pendingSave)    this._pendingSave   = null;
                if (rid === this._pendingDelete)  this._pendingDelete = null;

                return true;
            }
        }
    },

    /**
     * Called by msgProc() after a successful load response.
     * Assigns properties from the flat response object and snapshots
     * the data for change tracking.
     * @param {Object} data - Parsed response body
     */
    _onLoadSuccess(data) {
%s

        this._originalData = { ...data };
    },

    /**
     * Called by msgProc() after a successful save response.
     * Assigns any server-side changes (e.g. generated ID, timestamps)
     * back onto the abstraction and refreshes the change-tracking snapshot.
     * @param {Object} data - Parsed response body
     */
    _onSaveSuccess(data) {
%s

        this._originalData = { ...data };
    },

    /**
     * Called by msgProc() after a successful delete response.
     * DELETE returns 204 No Content, so there is no body to process.
     */
    _onDeleteSuccess() {
        this.reset();
        this._originalData = null;
    },

    /**
     * Centralised error handler.
     * Reads the { error: '...' } body when available, otherwise logs the raw error.
     * @param {Error|Object} error
     * @param {number}       httpStatus
     */
    _handleError(error, httpStatus) {
        const body = error && error.response && error.response.data;
        const message = (body && body.error) ? body.error : (error && error.message) || 'Request failed';
        console.error(`{$baseName} Error [\${httpStatus || 'Unknown'}]: \${message}`);
    }
}

/**
 * Factory helper — merges custom data / computed properties into a fresh
 * copy of the base abstraction and pre-seeds _originalData when initial
 * values are provided.
 * @param {Object} [customData={}]
 * @returns {Object}
 */
const create{$baseName}Abstraction = (customData = {}) => {
    const abstraction = {
        ...{$baseName}Abstraction,
        ...customData,

        computed: {
            ...{$baseName}Abstraction.computed,
            ...(customData.computed || {})
        }
    };

    if (customData && Object.keys(customData).length > 0) {
        abstraction._originalData = { ...customData };
    }

    return abstraction;
};

/**
 * CommonJS export
 */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { {$baseName}Abstraction, create{$baseName}Abstraction };
}
		"),
				implode(",\n", array_map(fn($e) => str_repeat(" ", 4) . $e, $components['properties'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['reset'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['changes'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['toPayload'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['assignAfterLoad'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['assignAfterSave'])),
			);
		}
		
		/**
		 * Creates JavaScript statements that serialize entity properties into a flat
		 * payload object for POST / PUT requests. Skips 'id' — the server assigns it
		 * on create and it is sent via the URL on update.
		 * @param array $columns Column mappings from entity metadata
		 * @param array $columnAnnotations Column annotation objects with type information
		 * @return array Array of JavaScript statements
		 */
		private function generateSerializeStatements(array $columns, array $columnAnnotations): array {
			$lines = [];
			
			foreach ($columns as $property => $column) {
				if ($property === 'id') {
					continue;
				}
				
				$annotation = $columnAnnotations[$property] ?? null;
				$columnType = $annotation ? $annotation->getType() : 'string';
				
				$lines = array_merge($lines, $this->generateSerializeForType($property, $columnType));
			}
			
			return $lines;
		}
		
		/**
		 * Generates serialization statements for a specific property type.
		 * Writes to a flat `payload` object.
		 * @param string $property
		 * @param string $columnType
		 * @return string[]
		 */
		private function generateSerializeForType(string $property, string $columnType): array {
			$baseCondition = "if (this.{$property} !== undefined && this.{$property} !== null) {";
			$closeCondition = "}";
			
			switch (strtolower($columnType)) {
				case 'boolean':
				case 'bool':
					return [
						$baseCondition,
						"    payload.{$property} = Boolean(this.{$property});",
						$closeCondition
					];
				
				case 'array':
				case 'json':
					return [
						$baseCondition,
						"    payload.{$property} = Array.isArray(this.{$property}) ? this.{$property} : JSON.parse(this.{$property});",
						$closeCondition
					];
				
				case 'datetime':
				case 'date':
					return [
						$baseCondition,
						"    payload.{$property} = this.{$property} instanceof Date ? this.{$property}.toISOString() : this.{$property};",
						$closeCondition
					];
				
				case 'integer':
				case 'int':
					return [
						$baseCondition,
						"    payload.{$property} = parseInt(this.{$property}, 10);",
						$closeCondition
					];
				
				case 'float':
				case 'decimal':
				case 'double':
					return [
						$baseCondition,
						"    payload.{$property} = parseFloat(this.{$property});",
						$closeCondition
					];
				
				default:
					return [
						$baseCondition,
						"    payload.{$property} = String(this.{$property});",
						$closeCondition
					];
			}
		}
	}