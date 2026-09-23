<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * Reads the SQL Server database compatibility level. It's a per-database setting independent
	 * of the engine version, so getServerVersion() alone can't tell which T-SQL features a database supports.
	 */
	class SqlServerCompatibilityLevelInspector {

		/**
		 * @var DatabaseAdapter
		 */
		private readonly DatabaseAdapter $adapter;

		/** @var int|null Cached compatibility level; null means not yet read, or the read failed */
		private ?int $compatibilityLevelCache = null;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Returns the current database's compatibility level (e.g. 170 for SQL Server 2025),
		 * or null if it could not be determined. A successful read is cached.
		 * @return int|null
		 */
		public function getCompatibilityLevel(): ?int {
			if ($this->compatibilityLevelCache !== null) {
				return $this->compatibilityLevelCache;
			}

			// DB_NAME() resolves to the current connection's database
			$stmt = $this->adapter->execute(
				"SELECT DATABASEPROPERTYEX(DB_NAME(), 'CompatibilityLevel') AS compat_level"
			);

			if ($stmt === null) {
				return null;
			}

			$row = $stmt->fetchAssoc();
			$stmt->closeCursor();

			if (!$row || !isset($row['compat_level'])) {
				return null;
			}

			return $this->compatibilityLevelCache = (int)$row['compat_level'];
		}
	}
