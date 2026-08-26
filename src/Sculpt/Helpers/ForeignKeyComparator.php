<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;

	/**
	 * @phpstan-import-type ForeignKeyDefinition from DatabaseAdapter
	 * @phpstan-import-type ForeignKeyChangeSet from SculptTypes
	 */
	class ForeignKeyComparator {

		/** @var DatabaseAdapter Database connection / interface with cakephp/database and Phinx */
		private DatabaseAdapter $connection;

		/** @var EntityStore EntityStore manages entity metadata and relations */
		private EntityStore $entityStore;

		/**
		 * ForeignKeyComparator constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
		}

		/**
		 * Compares database foreign keys with entity-declared @Orm\ForeignKey annotations
		 * to find missing, removed or inconsistent constraints.
		 * @param string|object $entity The entity class to analyze
		 * @return ForeignKeyChangeSet An array containing differences between DB and entity foreign keys
		 * @throws EntityResolutionException
		 * @throws \Exception
		 */
		public function compareForeignKeys(mixed $entity): array {
			$metadata = $this->entityStore->getMetadata($entity);
			$tableForeignKeys = $this->connection->getForeignKeys($metadata->tableName);
			$entityForeignKeys = $this->getEntityForeignKeys($entity);

			if (empty($tableForeignKeys) && empty($entityForeignKeys)) {
				return ['added' => [], 'modified' => [], 'deleted' => []];
			}

			$result = [
				'added'    => [],
				'modified' => [],
			];

			foreach ($entityForeignKeys as $name => $config) {
				if (!isset($tableForeignKeys[$name])) {
					$result['added'][$name] = $config;
				} elseif ($this->foreignKeyConfigDiffers($tableForeignKeys[$name], $config)) {
					$result['modified'][$name] = [
						'database' => $tableForeignKeys[$name],
						'entity'   => $config,
					];
				}

				unset($tableForeignKeys[$name]);
			}

			$result['deleted'] = $tableForeignKeys;
			return $result;
		}

		/**
		 * Builds the target foreign-key configuration declared by an entity's
		 * @Orm\ForeignKey annotations, keyed by a deterministic constraint name.
		 * @param string|object $entity The entity object or class to get foreign keys for
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 * @throws \Exception When a ForeignKey's target entity has no primary key to default to
		 */
		public function getEntityForeignKeys(mixed $entity): array {
			$metadata = $this->entityStore->getMetadata($entity);
			$result = [];

			foreach ($metadata->foreignKeys as $columnName => $annotation) {
				$targetMetadata = $this->entityStore->getMetadata($annotation->getTarget());
				$referencedColumn = $annotation->getReferencedColumn() ?? ($targetMetadata->identifierColumns[0] ?? null);

				if ($referencedColumn === null) {
					throw new \Exception(
						"ForeignKey on '{$metadata->tableName}.{$columnName}' targets " .
						"'{$annotation->getTarget()}', which has no primary key to default " .
						"referencedColumn to. Declare 'referencedColumn' explicitly."
					);
				}

				$name = 'fk_' . $metadata->tableName . '_' . $columnName;

				$result[$name] = [
					'columns'           => [$columnName],
					'referencedTable'   => $targetMetadata->tableName,
					'referencedColumns' => [$referencedColumn],
					'onDelete'          => $annotation->getOnDelete(),
					'onUpdate'          => $annotation->getOnUpdate(),
				];
			}

			return $result;
		}

		/**
		 * Compares two foreign key configurations to check if they differ.
		 * @param ForeignKeyDefinition $dbConfig Database foreign key configuration
		 * @param ForeignKeyDefinition $entityConfig Entity foreign key configuration
		 * @return bool True if configurations differ, false otherwise
		 */
		private function foreignKeyConfigDiffers(array $dbConfig, array $entityConfig): bool {
			if ($dbConfig['referencedTable'] !== $entityConfig['referencedTable']) {
				return true;
			}

			if ($dbConfig['onDelete'] !== $entityConfig['onDelete']) {
				return true;
			}

			if ($dbConfig['onUpdate'] !== $entityConfig['onUpdate']) {
				return true;
			}

			$dbColumns = $dbConfig['columns'];
			$entityColumns = $entityConfig['columns'];
			sort($dbColumns);
			sort($entityColumns);

			if ($dbColumns !== $entityColumns) {
				return true;
			}

			$dbReferencedColumns = $dbConfig['referencedColumns'];
			$entityReferencedColumns = $entityConfig['referencedColumns'];
			sort($dbReferencedColumns);
			sort($entityReferencedColumns);

			return $dbReferencedColumns !== $entityReferencedColumns;
		}
	}
