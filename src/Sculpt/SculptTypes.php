<?php
	
	namespace Quellabs\ObjectQuel\Sculpt;
	
	/**
	 * Shared PHPStan type aliases for the Sculpt subsystem.
	 *
	 * This class exists solely as a type-alias host. It is never instantiated.
	 * Import types via: @phpstan-import-type TypeName from SculptTypes
	 *
	 * -------------------------------------------------------------------------
	 * Entity property types (used by MakeEntityCommand and EntityModifier)
	 * -------------------------------------------------------------------------
	 *
	 * The standard Phinx column types available for entity properties.
	 * Excludes 'enum' (handled as EnumProperty) and 'relationship' (handled as RelationProperty),
	 * both of which require additional metadata and map to distinct property shapes.
	 *
	 * @phpstan-type PhinxColumnType 'tinyinteger'|'smallinteger'|'integer'|'biginteger'|'string'|'char'|'text'|'float'|'decimal'|'boolean'|'date'|'datetime'|'time'|'timestamp'
	 *
	 * @phpstan-type BaseProperty array{
	 *     name: string,
	 *     type: PhinxColumnType,
	 *     nullable?: bool,
	 *     readonly?: bool,
	 *     unsigned?: bool,
	 *     limit?: int|string,
	 *     precision?: int,
	 *     scale?: int
	 * }
	 *
	 * @phpstan-type EnumProperty array{
	 *     name: string,
	 *     type: 'enum',
	 *     nullable?: bool,
	 *     readonly?: bool,
	 *     enumType: string
	 * }
	 *
	 * The ORM relationship types supported by ObjectQuel.
	 *
	 * @phpstan-type OrmRelationshipType 'OneToOne'|'OneToMany'|'ManyToOne'
	 *
	 * Return type for relationship mapping configuration methods.
	 * The extended shape is returned when a reciprocal property should also be
	 * created in the target entity (createInTarget: true).
	 *
	 * @phpstan-type RelationshipMappingConfig array{mappedBy: string|null, inversedBy: string|null}
	 *                                        |array{
	 *                                            mappedBy: string|null,
	 *                                            inversedBy: string|null,
	 *                                            createInTarget: true,
	 *                                            targetPropertyName: string,
	 *                                            targetRelationType: OrmRelationshipType,
	 *                                            targetInversedBy: string|null
	 *                                        }
	 *
	 * @phpstan-type RelationProperty array{
	 *     name: string,
	 *     type: string,
	 *     nullable?: bool,
	 *     readonly?: bool,
	 *     relationshipType: OrmRelationshipType,
	 *     targetEntity: string,
	 *     mappedBy?: string|null,
	 *     inversedBy?: string|null,
	 *     relationColumn?: string|null,
	 *     foreignColumn?: string
	 * }
	 *
	 * @phpstan-type PropertyDefinition BaseProperty|EnumProperty|RelationProperty
	 *
	 * -------------------------------------------------------------------------
	 * Column / schema types
	 * -------------------------------------------------------------------------
	 *
	 * @phpstan-type ColumnDefinition array{
	 *     type: string,
	 *     limit?: int|string|array<int, int>,
	 *     nullable?: bool,
	 *     default?: mixed,
	 *     precision?: int,
	 *     scale?: int,
	 *     unsigned?: bool,
	 *     identity?: bool,
	 *     primary_key?: bool,
	 *     values?: array<int, string>
	 * }
	 *
	 * -------------------------------------------------------------------------
	 * Index types
	 * -------------------------------------------------------------------------
	 *
	 * @phpstan-type IndexDefinition array{
	 *     columns: array<int, string>,
	 *     type: string,
	 *     unique: bool
	 * }
	 *
	 * @phpstan-type IndexChangeSet array{
	 *     added: array<string, IndexDefinition>,
	 *     modified: array<string, array{
	 *         database: IndexDefinition,
	 *         entity: IndexDefinition
	 *     }>,
	 *     deleted: array<string, IndexDefinition>
	 * }
	 *
	 * -------------------------------------------------------------------------
	 * Composite types (depend on ColumnDefinition and IndexChangeSet)
	 * -------------------------------------------------------------------------
	 *
	 * A single entry from the 'modified' map: the before/after column definitions
	 * and a per-field breakdown of what changed.
	 *
	 * @phpstan-type ColumnModification array{
	 *     from: ColumnDefinition,
	 *     to: ColumnDefinition,
	 *     changes: array<string, array{from: mixed, to: mixed}>
	 * }
	 *
	 * @phpstan-type EntityChangeSet array{
	 *     table_not_exists?: bool,
	 *     added: array<string, ColumnDefinition>,
	 *     modified: array<string, ColumnModification>,
	 *     deleted: array<string, ColumnDefinition>,
	 *     indexes: IndexChangeSet
	 * }
	 */
	final class SculptTypes {
		private function __construct() {
		}
	}