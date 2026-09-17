<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	/**
	 * A single foreign key constraint's definition — the value object every
	 * DatabaseAdapter::getForeignKeys() implementation (see
	 * Inspector\SchemaIntrospectorInterface) returns, keyed by constraint
	 * name. Built by whichever per-engine introspector read it off a live
	 * table, or by ForeignKeyComparator from an entity's own
	 * @Orm\ForeignKey/@Orm\ForeignKeyAction annotations — both sides of
	 * every diff are this same type.
	 */
	final readonly class ForeignKeyDefinition {

		/** @var string[] Local column name(s) the constraint is declared on */
		public array $columns;

		/** @var string Table the constraint references */
		public string $referencedTable;

		/** @var string[] Column name(s) on the referenced table, positionally matching $columns */
		public array $referencedColumns;

		/** @var string ON DELETE rule, e.g. 'CASCADE', 'RESTRICT', 'NO ACTION' */
		public string $onDelete;

		/** @var string ON UPDATE rule, e.g. 'CASCADE', 'RESTRICT', 'NO ACTION' */
		public string $onUpdate;

		/**
		 * @param string[] $columns
		 * @param string $referencedTable
		 * @param string[] $referencedColumns
		 * @param string $onDelete
		 * @param string $onUpdate
		 */
		public function __construct(
			array $columns,
			string $referencedTable,
			array $referencedColumns,
			string $onDelete,
			string $onUpdate
		) {
			$this->columns = $columns;
			$this->referencedTable = $referencedTable;
			$this->referencedColumns = $referencedColumns;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
		}
	}
