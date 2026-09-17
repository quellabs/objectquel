<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	
	/**
	 * Class SchemaComparator
	 * Compares entity schema (object properties) with database schema (table columns)
	 * to identify changes such as added, modified, or deleted columns.
	 *
	 * The two array shapes below both mirror ColumnDefinition::toArray(),
	 * entered only once addDefaultValues() has finished with the real
	 * object — everything from filterRelevantProperties() onward is a
	 * comparison-only representation, not the value object itself.
	 * ColumnDefinitionArray (every key required) is ColumnDefinition::
	 * toArray()'s own shape, used only as filterRelevantProperties()'s
	 * pre-filter input. NormalizedColumnDefinition (every key optional) is
	 * what filterRelevantProperties() produces — it deliberately drops keys
	 * that don't matter for the column's type, so it's a genuine subset,
	 * not a full ColumnDefinition.
	 * @phpstan-type ColumnDefinitionArray array{
	 *     type: string,
	 *     php_type: string,
	 *     limit: int|array<int, int>|null,
	 *     default: mixed,
	 *     nullable: bool,
	 *     precision: int|null,
	 *     scale: int|null,
	 *     unsigned: bool,
	 *     generated: mixed,
	 *     identity: bool,
	 *     primary_key: bool,
	 *     values: array<int, string>|null
	 * }
	 * @phpstan-type NormalizedColumnDefinition array{
	 *     type: string,
	 *     php_type?: string,
	 *     limit?: int|array<int, int>|null,
	 *     default?: mixed,
	 *     nullable?: bool,
	 *     precision?: int|null,
	 *     scale?: int|null,
	 *     unsigned?: bool,
	 *     generated?: mixed,
	 *     identity?: bool,
	 *     primary_key?: bool,
	 *     values?: array<int, string>|null
	 * }
	 * @phpstan-import-type ColumnModification from SculptTypes
	 */
	class SchemaComparator {
		
		private const array NUMERIC_PROPERTIES = ['limit', 'precision', 'scale'];
		private const array BOOLEAN_PROPERTIES = ['nullable', 'unsigned', 'identity'];
		
		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * SchemaComparator constructor
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->platform = $platform;
		}
		
		/**
		 * Main public method to compare entity properties with table columns
		 * Identifies added, modified, and deleted columns
		 * @param array<string, ColumnDefinition> $entityColumns Map of property names to definitions from entity model
		 * @param array<string, ColumnDefinition> $tableColumns Map of column names to definitions from database
		 * @return array{added: array<string, ColumnDefinition>, modified: array<string, ColumnModification>, deleted: array<string, ColumnDefinition>}
		 * @throws \InvalidArgumentException If input arrays are malformed
		 */
		public function analyzeSchemaChanges(array $entityColumns, array $tableColumns): array {
			$this->validateInput($entityColumns, 'entityColumns');
			$this->validateInput($tableColumns, 'tableColumns');
			
			return [
				'added'    => $this->getAddedColumns($entityColumns, $tableColumns),
				'modified' => $this->getModifiedColumns($entityColumns, $tableColumns),
				'deleted'  => $this->getDeletedColumns($entityColumns, $tableColumns)
			];
		}
		
		/**
		 * Get columns that exist in entity but not in table
		 * @param array<string, ColumnDefinition> $entityColumns Map of property names to definitions from entity model
		 * @param array<string, ColumnDefinition> $tableColumns Map of column names to definitions from database
		 * @return array<string, ColumnDefinition> Columns that need to be added to the table
		 */
		private function getAddedColumns(array $entityColumns, array $tableColumns): array {
			return array_diff_key($entityColumns, $tableColumns);
		}
		
		/**
		 * Get columns that exist in table but not in entity
		 * @param array<string, ColumnDefinition> $entityColumns Map of property names to definitions from entity model
		 * @param array<string, ColumnDefinition> $tableColumns Map of column names to definitions from database
		 * @return array<string, ColumnDefinition> Columns that need to be deleted from the table
		 */
		private function getDeletedColumns(array $entityColumns, array $tableColumns): array {
			return array_diff_key($tableColumns, $entityColumns);
		}
		
		/**
		 * Get columns that exist in both but have differences
		 * @param array<string, ColumnDefinition> $entityColumns Definition of properties from the entity model
		 * @param array<string, ColumnDefinition> $tableColumns Definition of columns from the database table
		 * @return array<string, ColumnModification> Columns that need to be modified in the table
		 */
		private function getModifiedColumns(array $entityColumns, array $tableColumns): array {
			$result = [];
			
			foreach (array_intersect_key($entityColumns, $tableColumns) as $columnName => $entityColumn) {
				$normalizedEntity = $this->normalizeColumnDefinition($entityColumn);
				$normalizedTable = $this->normalizeColumnDefinition($tableColumns[$columnName]);

				if ($normalizedEntity !== $normalizedTable) {
					// 'from'/'to' carry the original, un-normalized definitions —
					// not the normalized copies used only for the equality check
					// above. Normalizing collapses 'enum' to 'string' on engines
					// without native ENUM (Step 2 of normalizeColumnDefinition()),
					// which is correct for comparison but would otherwise leak
					// into the generated migration, permanently losing the
					// column's real type and values list.
					$result[$columnName] = [
						'from'    => $tableColumns[$columnName],
						'to'      => $entityColumn,
						'changes' => $this->identifySpecificChanges($normalizedTable, $normalizedEntity)
					];
				}
			}
			
			return $result;
		}
		
		/**
		 * Identify specific properties that changed between two column definitions
		 * @param NormalizedColumnDefinition $from Original column definition (normalized)
		 * @param NormalizedColumnDefinition $to New column definition (normalized)
		 * @return array<string, array{from: mixed, to: mixed}> Map of property names to their before/after values
		 */
		private function identifySpecificChanges(array $from, array $to): array {
			$changes = [];
			$allKeys = array_unique(array_merge(array_keys($from), array_keys($to)));
			
			foreach ($allKeys as $key) {
				$fromValue = $from[$key] ?? null;
				$toValue = $to[$key] ?? null;
				
				if ($fromValue !== $toValue) {
					$changes[$key] = [
						'from' => $fromValue,
						'to'   => $toValue
					];
				}
			}
			
			return $changes;
		}
		
		/**
		 * Normalize column definition for consistent comparison. Returns a
		 * plain associative array, not another ColumnDefinition — Step 3
		 * below deliberately drops properties irrelevant to the column's
		 * type, so the result is a filtered comparison fingerprint, not a
		 * valid reconstruction of the input.
		 * @param ColumnDefinition $columnDefinition The column definition to normalize
		 * @return NormalizedColumnDefinition Normalized, filtered column definition
		 */
		private function normalizeColumnDefinition(ColumnDefinition $columnDefinition): array {
			// Step 1: Add any missing default values to ensure all required properties are present
			$normalized = $this->addDefaultValues($columnDefinition)->toArray();

			// Step 2: If database does not support ENUM, normalize enum to string
			if ($normalized['type'] === 'enum' && !$this->platform->supportsNativeEnums()) {
				$normalized['type'] = 'string';
			}
			
			// Step 2b: Normalize the database-native JSON type back to the ORM canonical
			// type 'json'. On PostgreSQL the database returns 'jsonb', but the entity
			// always declares 'json'. Without this step every run would generate a
			// spurious ALTER COLUMN for every JSON column on PostgreSQL.
			if ($normalized['type'] === $this->platform->getNativeJsonType() && $normalized['type'] !== 'json') {
				$normalized['type'] = 'json';
			}

			// Step 2c: collapse to whatever this platform's introspection can
			// actually distinguish (e.g. SQLite's json/uuid -> text) — see
			// TypeMapper::collapseForIntrospection().
			$normalized['type'] = TypeMapper::collapseForIntrospection($normalized['type'], $this->platform->getDatabaseType());

			// Step 3: Remove irrelevant or comparison-specific properties that shouldn't affect equality
			$normalized = $this->filterRelevantProperties($normalized);
			
			// Step 4: Standardize property values to a consistent format
			$normalized = $this->normalizePropertyValues($normalized);
			
			// Step 5: Sort the array keys alphabetically for consistent ordering
			ksort($normalized);
			
			// Return the sorted result
			return $normalized;
		}
		
		/**
		 * Add default values where missing. Takes and returns the real
		 * ColumnDefinition object — unlike every step after it, "fill in a
		 * missing default" doesn't drop or reduce any property, so the
		 * result is still a genuine, reconstructable ColumnDefinition, not
		 * yet the comparison-only array representation the later steps need.
		 * @param ColumnDefinition $columnDefinition Raw column definition
		 * @return ColumnDefinition Column definition with default values added
		 */
		private function addDefaultValues(ColumnDefinition $columnDefinition): ColumnDefinition {
			// Limit already set — nothing to fill in
			if ($columnDefinition->limit !== null) {
				return $columnDefinition;
			}

			if ($columnDefinition->type === 'enum' && !empty($columnDefinition->values)) {
				// Must match DDLTypeMapper's fallback-VARCHAR sizing exactly
				// (TypeMapper::enumFallbackLimit()) — a mismatch here means
				// this diff predicts a different limit than the DDL compiler
				// actually renders, producing a spurious diff forever.
				$limit = TypeMapper::enumFallbackLimit($columnDefinition->values);
			} else {
				$limit = TypeMapper::getDefaultLimit($columnDefinition->type);
			}

			// ColumnDefinition is readonly — "filling in" the limit means
			// building a new instance with every other field carried over unchanged.
			$fields = $columnDefinition->toArray();
			$fields['limit'] = $limit;
			return new ColumnDefinition(...$fields);
		}
		
		/**
		 * Filter to only include properties relevant to the column type.
		 * Operates on the array representation (see normalizeColumnDefinition()),
		 * not the ColumnDefinition object itself.
		 * @param ColumnDefinitionArray $columnDefinition Column definition with all properties
		 * @return NormalizedColumnDefinition Column definition with only type-relevant properties
		 */
		private function filterRelevantProperties(array $columnDefinition): array {
			$columnType = $columnDefinition['type'];
			$relevantProperties = TypeMapper::getRelevantProperties($columnType);

			// The connected engine has no UNSIGNED integer modifier at all (SQLite,
			// PostgreSQL, SQL Server). Schema introspection on these engines cannot
			// report 'unsigned' back from a real column, so comparing it here would
			// forever detect a false difference between the entity's declared
			// unsigned=true and whatever the introspected value happens to default to.
			if (!$this->platform->supportsUnsignedIntegers()) {
				$relevantProperties = array_diff($relevantProperties, ['unsigned']);
			}

			// array_intersect_key always preserves 'type' because it is in every relevantProperties
			// list, but PHPStan models the result as having all keys optional.
			/** @var NormalizedColumnDefinition $filtered */
			$filtered = array_intersect_key($columnDefinition, array_flip($relevantProperties));
			return $filtered;
		}

		/**
		 * Normalize property values for consistent comparison. Operates on
		 * the array representation (see normalizeColumnDefinition()), not
		 * the ColumnDefinition object itself.
		 * @param NormalizedColumnDefinition $columnDefinition Column definition to normalize
		 * @return NormalizedColumnDefinition Column definition with normalized property values
		 */
		private function normalizePropertyValues(array $columnDefinition): array {
			// Extract column type
			$columnType = $columnDefinition['type'];

			// Normalize each property value based on its property name and the column type
			$result = [];

			foreach ($columnDefinition as $property => $value) {
				$result[$property] = $this->normalizePropertyValue($property, $value, $columnType);
			}

			// PHPStan can't follow the shape through a dynamic key-by-key rebuild;
			// it genuinely still matches NormalizedColumnDefinition — same keys in,
			// same keys out, only values changed — matching this method's own
			// pre-existing convention for this exact situation.
			/** @var NormalizedColumnDefinition $result */
			return $result;
		}
		
		/**
		 * Normalize a specific property value based on its type and column context
		 * @param string $property Property name
		 * @param mixed $value Property value to normalize
		 * @param string $columnType The column type for context
		 * @return mixed Normalized property value
		 */
		private function normalizePropertyValue(string $property, mixed $value, string $columnType): mixed {
			// Convert numeric properties to integers if the value is numeric
			// This ensures consistent data types for properties like length, precision, scale, etc.
			if (in_array($property, self::NUMERIC_PROPERTIES) && is_numeric($value)) {
				return (int)$value;
			}
			
			// Convert boolean properties to actual boolean values
			// This handles properties like nullable, unsigned, auto_increment, etc.
			if (in_array($property, self::BOOLEAN_PROPERTIES)) {
				return (bool)$value;
			}
			
			// Clean up string values by removing leading/trailing whitespace
			// This ensures consistent formatting for string properties
			if (is_string($value)) {
				return trim($value);
			}
			
			// Return the value unchanged if no specific normalization rules apply
			// This preserves the original value for unsupported types or edge cases
			return $value;
		}
		
		/**
		 * Validate input arrays to ensure they have the expected structure
		 * @param array<string, mixed> $columns Array of column definitions to validate
		 * @param string $parameterName Name of the parameter being validated (for error messages)
		 * @return void
		 * @throws \InvalidArgumentException If validation fails
		 */
		private function validateInput(array $columns, string $parameterName): void {
			foreach ($columns as $columnName => $columnDefinition) {
				if (!$columnDefinition instanceof ColumnDefinition) {
					throw new \InvalidArgumentException(
						"Invalid column definition for '{$columnName}' in {$parameterName}: expected " .
						ColumnDefinition::class . ", got " . get_debug_type($columnDefinition)
					);
				}
			}
		}
	}