<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	/**
	 * Typed carrier for CreateIndexExecutor::resolveFulltextPrerequisites()'s
	 * result: the base table's primary key column (sqlite FTS5
	 * content_rowid) and an existing unique/primary index name (sqlsrv's KEY
	 * INDEX clause). Both null outside those two dialects.
	 */
	final class FulltextPrerequisites {

		private ?string $primaryKeyColumn;
		private ?string $sqlServerKeyIndexName;

		/**
		 * FulltextPrerequisites constructor
		 * @param string|null $primaryKeyColumn
		 * @param string|null $sqlServerKeyIndexName
		 */
		public function __construct(?string $primaryKeyColumn, ?string $sqlServerKeyIndexName) {
			$this->primaryKeyColumn = $primaryKeyColumn;
			$this->sqlServerKeyIndexName = $sqlServerKeyIndexName;
		}

		/**
		 * @return string|null
		 */
		public function getPrimaryKeyColumn(): ?string {
			return $this->primaryKeyColumn;
		}

		/**
		 * @return string|null
		 */
		public function getSqlServerKeyIndexName(): ?string {
			return $this->sqlServerKeyIndexName;
		}
	}
