<?php
	
	namespace Quellabs\ObjectQuel\Sculpt;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	
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
	 * A real database-level FK constraint attached to a scalar or FK-column
	 * property, mirroring @Orm\ForeignKey / @Orm\ForeignKeyAction. onDelete/
	 * onUpdate always carry a concrete value here (defaults included) — the
	 * generator decides whether ForeignKeyAction is worth emitting by
	 * comparing them against RESTRICT/NO ACTION, same convention as
	 * MakeEntityFromTableCommand.
	 *
	 * @phpstan-type ForeignKeyConstraint array{
	 *     target: string,
	 *     onDelete: string,
	 *     onUpdate: string
	 * }
	 *
	 * @phpstan-type BaseProperty array{
	 *     name: string,
	 *     type: PhinxColumnType,
	 *     nullable?: bool,
	 *     readonly?: bool,
	 *     unsigned?: bool,
	 *     limit?: int|string,
	 *     precision?: int,
	 *     scale?: int,
	 *     foreignKey?: ForeignKeyConstraint
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
	 * @phpstan-type OrmRelationshipType 'OneToOne'|'InverseOf'|'ManyToOne'
	 *
	 * Return type for relationship mapping configuration methods.
	 * The extended shape is returned when a reciprocal property should also be
	 * created in the target entity (createInTarget: true).
	 *
	 * @phpstan-type RelationshipMappingConfig array{relation: string|null, referencedColumn: string|null}
	 *                                        |array{
	 *                                            relation: string|null,
	 *                                            referencedColumn: string|null,
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
	 *     relation?: string|null,
	 *     referencedColumn?: string|null,
	 *     localColumn?: string|null,
	 *     collection?: bool
	 * }
	 *
	 * @phpstan-type PropertyDefinition BaseProperty|EnumProperty|RelationProperty
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
	 * Foreign key types
	 * -------------------------------------------------------------------------
	 *
	 * @phpstan-import-type ForeignKeyDefinition from DatabaseAdapter
	 *
	 * @phpstan-type ForeignKeyChangeSet array{
	 *     added: array<string, ForeignKeyDefinition>,
	 *     modified: array<string, array{
	 *         database: ForeignKeyDefinition,
	 *         entity: ForeignKeyDefinition
	 *     }>,
	 *     deleted: array<string, ForeignKeyDefinition>
	 * }
	 *
	 * -------------------------------------------------------------------------
	 * Composite types (depend on ColumnDefinition, IndexChangeSet and ForeignKeyChangeSet)
	 * -------------------------------------------------------------------------
	 *
	 * A single entry from the 'modified' map: the before/after column definitions
	 * and a per-field breakdown of what changed.
	 *
	 * @phpstan-import-type ColumnDefinition from DatabaseAdapter
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
	 *     indexes: IndexChangeSet,
	 *     foreignKeys: ForeignKeyChangeSet
	 * }
	 */
	final class SculptTypes {}