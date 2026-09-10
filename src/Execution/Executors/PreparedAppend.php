<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;

	/**
	 * Typed carrier for AppendExecutor::prepare()/fillGeneratedPrimaryKeys()'s
	 * result: the (possibly rewritten) statement, its entity metadata, and
	 * the primary key generated for a single-row literal-values append
	 * (null otherwise).
	 */
	final class PreparedAppend {

		private AstAppend $statement;
		private EntityMetadataRecord $metadata;
		private mixed $generatedId;

		/**
		 * PreparedAppend constructor
		 * @param AstAppend $statement
		 * @param EntityMetadataRecord $metadata
		 * @param mixed $generatedId
		 */
		public function __construct(AstAppend $statement, EntityMetadataRecord $metadata, mixed $generatedId) {
			$this->statement = $statement;
			$this->metadata = $metadata;
			$this->generatedId = $generatedId;
		}

		/**
		 * Returns the (possibly rewritten) append statement.
		 * @return AstAppend
		 */
		public function getStatement(): AstAppend {
			return $this->statement;
		}

		/**
		 * Returns the target entity's metadata.
		 * @return EntityMetadataRecord
		 */
		public function getMetadata(): EntityMetadataRecord {
			return $this->metadata;
		}

		/**
		 * Returns the generated primary key, or null when none was generated.
		 * @return mixed
		 */
		public function getGeneratedId(): mixed {
			return $this->generatedId;
		}
	}
