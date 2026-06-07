<?php
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	
	/**
	 * Value object returned by EntityMetadataBuilder::extractColumnData().
	 * Carries all column-derived metadata extracted in a single annotation pass.
	 */
	class ColumnData {
		
		/**
		 * @param array<string, string> $columnMap Property name => database column name
		 * @param list<string> $identifierKeys PHP property names of primary key columns
		 * @param list<string> $identifierColumns Database column names of primary key columns
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns
		 * @param string|null $autoIncrementColumn Property name of the auto-increment column, or null
		 */
		public function __construct(
			public readonly array $columnMap,
			public readonly array $identifierKeys,
			public readonly array $identifierColumns,
			public readonly array $versionColumns,
			public readonly ?string $autoIncrementColumn,
		) {}
	}