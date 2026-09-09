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

		public function __construct(AstAppend $statement, EntityMetadataRecord $metadata, mixed $generatedId) {
			$this->statement = $statement;
			$this->metadata = $metadata;
			$this->generatedId = $generatedId;
		}

		public function getStatement(): AstAppend {
			return $this->statement;
		}

		public function getMetadata(): EntityMetadataRecord {
			return $this->metadata;
		}

		public function getGeneratedId(): mixed {
			return $this->generatedId;
		}
	}
