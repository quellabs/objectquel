<?php
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	
	/**
	 * Value object returned by EntityMetadataBuilder::extractColumnData().
	 * Carries all column-derived metadata extracted in a single annotation pass.
	 */
	readonly class ColumnData {
		
		/**
		 * @param array<string, string> $columnMap Property name => database column name
		 * @param list<string> $identifierKeys PHP property names of primary key columns
		 * @param list<string> $identifierColumns Database column names of primary key columns
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns
		 * @param string|null $autoIncrementColumn Property name of the auto-increment column, or null
		 * @param string|null $softDeleteProperty Property name of the @SoftDelete column, or null
		 * @param string|null $softDeleteColumn Database column name of the @SoftDelete column, or null
		 * @param string|null $softDeleteColumnType Column type of the @SoftDelete column ('datetime', 'boolean', etc.), or null
		 */
		public function __construct(
			public array $columnMap,
			public array $identifierKeys,
			public array $identifierColumns,
			public array $versionColumns,
			public ?string $autoIncrementColumn,
			public ?string $softDeleteProperty = null,
			public ?string $softDeleteColumn = null,
			public ?string $softDeleteColumnType = null,
		) {}
	}