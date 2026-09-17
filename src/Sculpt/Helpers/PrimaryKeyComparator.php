<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;

	/**
	 * Compares an entity's declared primary key (the union of every
	 * @Orm\Column(primary_key=true) property, in declaration order) against
	 * the live table's actual primary key. Sibling to IndexComparator/
	 * ForeignKeyComparator, but a table has at most one primary key, so the
	 * result is a single optional change rather than a keyed map of many.
	 *
	 * Column-level diffing (SchemaComparator) deliberately never looks at
	 * 'primary_key' — see TypeMapper::getRelevantProperties() — since a
	 * column can't tell whether it's part of a composite key just by
	 * comparing its own properties one column at a time. This class exists
	 * to cover exactly that gap.
	 *
	 * @phpstan-import-type PrimaryKeyChangeSet from SculptTypes
	 */
	class PrimaryKeyComparator {

		/** @var DatabaseAdapter Database connection / interface with cakephp/database */
		private DatabaseAdapter $connection;

		/** @var EntityStore EntityStore manages entity metadata and relations */
		private EntityStore $entityStore;

		/**
		 * PrimaryKeyComparator constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
		}

		/**
		 * Compares the entity's declared primary key against the live
		 * table's actual one. Only call this for a table that already
		 * exists — a brand-new table's primary key is embedded directly in
		 * its `create` statement (see QuelMigrationBuilder::
		 * buildCreateTableStatement()), never through this diff.
		 * @param string|object $entity The entity class to analyze
		 * @return PrimaryKeyChangeSet
		 * @throws EntityResolutionException
		 */
		public function comparePrimaryKey(mixed $entity): array {
			$metadata = $this->entityStore->getMetadata($entity);

			// Declaration order matters for the resulting DDL (a composite
			// key's column order is significant), so this is taken as-is —
			// never sorted — unlike the equality check just below.
			$entityColumns = $metadata->identifierColumns;
			$tableColumns = $this->connection->getPrimaryKeyColumns($metadata->tableName);

			if ($entityColumns === [] && $tableColumns === []) {
				return ['action' => null];
			}

			// Order doesn't affect whether two key definitions are
			// equivalent (MySQL/Postgres/etc. all treat a composite key as
			// a set for equality purposes here), only whether emitting a
			// change is warranted at all.
			$entitySorted = $entityColumns;
			$tableSorted = $tableColumns;
			sort($entitySorted);
			sort($tableSorted);

			if ($entitySorted === $tableSorted) {
				return ['action' => null];
			}

			if ($entityColumns === []) {
				return ['action' => 'drop', 'from' => $tableColumns];
			}

			// Covers both "no primary key existed yet" and "one exists but
			// its columns differ" — compileSetPrimaryKey() already handles
			// both uniformly (drops the old one first only when one
			// exists), so the diff doesn't need to distinguish them either.
			return ['action' => 'set', 'columns' => $entityColumns, 'from' => $tableColumns];
		}
	}
