<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Compile-time checks shared by every write verb whose AST carries
	 * AstAssignment nodes (`append`, `replace`) — an assignment's left-hand
	 * side is always a plain property name string rather than an
	 * AstIdentifier, so it never passes through ValidateEntityPropertyExists/
	 * ResolvePropertyType (those only visit AstIdentifier nodes reached via
	 * accept()). These two checks fill that gap once, instead of each
	 * QuelToSQL* compiler reimplementing its own copy (see
	 * objectquel-append-plan.md / objectquel-replace-plan.md).
	 */
	class AssignmentValidator {

		/**
		 * Every assigned property must exist on the target entity.
		 * @param string[] $properties
		 * @param EntityMetadataRecord $metadata
		 * @return void
		 * @throws SemanticException
		 */
		public static function assertPropertiesExist(array $properties, EntityMetadataRecord $metadata): void {
			foreach ($properties as $property) {
				if (!isset($metadata->columnMap[$property])) {
					throw new SemanticException(
						"The property '{$property}' does not exist in entity '{$metadata->className}'. " .
						"Please check for typos or verify that the correct entity is being referenced in the statement."
					);
				}
			}
		}

		/**
		 * Best-effort static type check between a literal assignment value and
		 * its target column's declared type. Parameters, casts, and arithmetic
		 * expressions carry no static type and are left to the database.
		 * @param string $property
		 * @param AstInterface $value
		 * @param array{type: string, nullable: bool} $columnDef
		 * @return void
		 * @throws SemanticException
		 */
		public static function assertValueTypeCompatible(string $property, AstInterface $value, array $columnDef): void {
			$expectedPhpType = TypeMapper::phinxTypeToPhpType($columnDef['type']);

			if ($value instanceof AstNull) {
				if (!$columnDef['nullable']) {
					throw new SemanticException("Cannot assign null to non-nullable property '{$property}'");
				}

				return;
			}

			if ($value instanceof AstBool && $expectedPhpType !== 'bool') {
				throw new SemanticException("Cannot assign a boolean value to property '{$property}' (expects {$columnDef['type']})");
			}

			if ($value instanceof AstNumber && in_array($expectedPhpType, ['bool', '\DateTime', 'array'], true)) {
				throw new SemanticException("Cannot assign a numeric value to property '{$property}' (expects {$columnDef['type']})");
			}

			if ($value instanceof AstString && in_array($expectedPhpType, ['bool', 'array'], true)) {
				throw new SemanticException("Cannot assign a string value to property '{$property}' (expects {$columnDef['type']})");
			}
		}
	}
