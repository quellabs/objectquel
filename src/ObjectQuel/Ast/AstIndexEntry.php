<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * Shared shape of an embedded index descriptor — AstCreateTableIndex
	 * (create's embedded `index name (...)` entries) and AstAlterAddIndex
	 * (alter's `add index name (...)` sub-operation) both implement this so
	 * AstCreateIndex::fromEntry() can assemble either into a real
	 * AstCreateIndex without CreateTableExecutor/AlterTableExecutor each
	 * needing their own near-identical conversion method (see
	 * objectquel-index-clause-design.md, "Sugar, not reimplementation").
	 */
	interface AstIndexEntry {

		/**
		 * Returns the index name.
		 * @return string
		 */
		public function getIndexName(): string;

		/**
		 * @return string[]
		 */
		public function getColumns(): array;

		/**
		 * Reports whether the index is unique.
		 * @return bool
		 */
		public function isUnique(): bool;

		/**
		 * Returns the type.
		 * @return ?string
		 */
		public function getType(): ?string;
	}
