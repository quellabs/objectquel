<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	/**
	 * Shared validation for the plural 'localColumns'/'referencedColumns' relation
	 * parameters, used identically by ManyToOne and OneToOne. Both name ordered lists
	 * of PHP property names (matching the existing singular 'localColumn'/'referencedColumn'
	 * convention), paired by index, for a relation that spans more than one column.
	 */
	final class AnnotationColumnArrays {
		
		/**
		 * Validates the plural column-array parameters of a relation annotation.
		 * @param string $annotationName Name used in error messages (e.g. 'ManyToOne')
		 * @param mixed $localColumns Raw 'localColumns' parameter value
		 * @param mixed $referencedColumns Raw 'referencedColumns' parameter value
		 * @param string|null $localColumn The same annotation's singular 'localColumn', if given
		 * @param string|null $referencedColumn The same annotation's singular 'referencedColumn', if given
		 * @throws \InvalidArgumentException
		 */
		public static function validate(
			string $annotationName,
			mixed $localColumns,
			mixed $referencedColumns,
			?string $localColumn,
			?string $referencedColumn
		): void {
			if ($localColumns === null && $referencedColumns === null) {
				return;
			}
			
			if ($localColumns === null || $referencedColumns === null) {
				throw new \InvalidArgumentException(
					"{$annotationName}: 'localColumns' and 'referencedColumns' must both be given together"
				);
			}
			
			if ($localColumn !== null || $referencedColumn !== null) {
				throw new \InvalidArgumentException(
					"{$annotationName}: 'localColumns'/'referencedColumns' cannot be combined with " .
					"'localColumn'/'referencedColumn' on the same relation"
				);
			}
			
			if (!is_array($localColumns) || !is_array($referencedColumns)) {
				throw new \InvalidArgumentException(
					"{$annotationName}: 'localColumns' and 'referencedColumns' must be arrays"
				);
			}
			
			if ($localColumns === [] || $referencedColumns === []) {
				throw new \InvalidArgumentException(
					"{$annotationName}: 'localColumns' and 'referencedColumns' must not be empty"
				);
			}
			
			if (count($localColumns) !== count($referencedColumns)) {
				throw new \InvalidArgumentException(
					"{$annotationName}: 'localColumns' and 'referencedColumns' must have the same number of elements"
				);
			}
			
			self::assertStringList($annotationName, 'localColumns', $localColumns);
			self::assertStringList($annotationName, 'referencedColumns', $referencedColumns);
		}
		
		/**
		 * Narrows an already-validate()d column-array parameter to a proper list<string>
		 * for the annotation's own property — validate() has already thrown on any
		 * non-string/empty entry, so this never actually drops anything at runtime;
		 * it exists so the property assignment is statically known to be list<string>
		 * rather than mixed.
		 * @param mixed $value The raw 'localColumns'/'referencedColumns' parameter value
		 * @return list<string>
		 */
		public static function normalizeList(mixed $value): array {
			$result = [];
			
			if (!is_array($value)) {
				return $result;
			}
			
			foreach ($value as $entry) {
				if (is_string($entry) && $entry !== '') {
					$result[] = $entry;
				}
			}
			
			return $result;
		}
		
		/**
		 * @param array<mixed> $values
		 */
		private static function assertStringList(string $annotationName, string $parameterName, array $values): void {
			foreach ($values as $value) {
				if (!is_string($value) || $value === '') {
					throw new \InvalidArgumentException(
						"{$annotationName}: '{$parameterName}' entries must be non-empty strings"
					);
				}
			}
		}
	}
