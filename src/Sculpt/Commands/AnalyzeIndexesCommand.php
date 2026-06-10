<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * AnalyzeIndexesCommand - Reports redundant database indexes across all tables
	 *
	 * Scans every table in the database and identifies indexes that are made
	 * redundant by another index on the same table. Two redundancy cases are
	 * detected:
	 *
	 *   - Exact duplicates: two indexes cover the exact same columns in the
	 *     same order but have different names.
	 *
	 *   - Prefix redundancy: an index on (a) is made redundant when another
	 *     index on (a, b) exists, because the wider index already satisfies
	 *     every lookup the narrower one could serve.
	 *
	 * Primary key indexes are never flagged; they have structural significance
	 * beyond query optimisation and cannot be dropped independently.
	 *
	 * Usage statistics are included when the database supports them:
	 *   - MySQL / MariaDB : performance_schema.table_io_waits_summary_by_index_usage
	 *   - PostgreSQL      : pg_stat_user_indexes
	 *   - SQLite          : not available (silently omitted)
	 *
	 * Usage stats require the performance_schema to be enabled on MySQL/MariaDB.
	 * When the schema is unavailable the command falls back to structural analysis
	 * only and notes that usage data could not be retrieved.
	 */
	class AnalyzeIndexesCommand extends MakeCommandBase {
		
		/**
		 * @param ConsoleInput    $input    Console input handler
		 * @param ConsoleOutput   $output   Console output handler
		 * @param ServiceProvider $provider Service provider exposing configuration and DB adapter
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Returns the Sculpt command signature used to invoke this command.
		 * @return string
		 */
		public function getSignature(): string {
			return "quel:analyze-indexes";
		}
		
		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "Analyse database indexes and report redundant ones that can be safely removed.";
		}
		
		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Scans all tables in the database for redundant indexes and reports findings
    in a structured table, including usage statistics where available.

    Two types of redundancy are detected:

      Exact duplicate   Two indexes cover the identical column set in the same
                        order. The second one is entirely redundant.

      Prefix redundancy An index on (a) is made redundant by an existing index
                        on (a, b), because the wider index already handles every
                        lookup the narrower one would serve.

    Primary key indexes are never flagged; they have structural significance
    beyond query planning and must not be dropped.

USAGE STATISTICS:
    When available, the read and write hit counts for each index are shown.
    This helps confirm that a structurally redundant index is also unused before
    recommending its removal.

      MySQL / MariaDB   Reads performance_schema.table_io_waits_summary_by_index_usage.
                        Requires performance_schema to be enabled (it is on by
                        default in MySQL 5.6+ and MariaDB 10.6+). If disabled,
                        add 'performance_schema = ON' under [mysqld] in my.cnf
                        and restart the server. Counters reset on server restart.

      PostgreSQL        Reads pg_stat_user_indexes. Always available; counters
                        reset on server restart or pg_stat_reset().

      SQLite            No index usage statistics are available at the engine
                        level. The column is omitted from output.

USAGE:
    php sculpt quel:analyze-indexes

ARGUMENTS:
    None
HELP;
		}
		
		/**
		 * Execute the index analysis command.
		 *
		 * Workflow:
		 *   1. Fetch all tables from the database.
		 *   2. For each table, retrieve its index definitions.
		 *   3. Load per-index usage statistics from the engine where supported.
		 *   4. Detect redundant indexes (exact duplicates and prefix-covered indexes).
		 *   5. Render a single flat table — one row per index across all tables —
		 *      with a Status column showing the verdict inline.
		 *
		 * @param ConfigurationManager $config Provides access to CLI arguments
		 * @return int 0 on success, 1 on any error
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				/** @var ServiceProvider $provider */
				$provider        = $this->provider;
				$databaseAdapter = $provider->getDatabaseAdapter();
				$dbType          = $databaseAdapter->getDatabaseType();
				
				// Retrieve all table names in the database
				$tables = $databaseAdapter->getTables();
				
				if (empty($tables)) {
					$this->output->writeLn("No tables found in the database.");
					return 0;
				}
				
				// Load usage statistics once for all tables (where supported)
				$usageStats     = $this->loadUsageStats($databaseAdapter, $dbType, $tables);
				$usageAvailable = $usageStats !== null;
				
				// Collect index definitions and redundancy findings per table
				$tableIndexes  = [];
				$tableFindings = [];
				
				foreach ($tables as $tableName) {
					$indexes                   = $databaseAdapter->getIndexes($tableName);
					$tableIndexes[$tableName]  = $indexes;
					$tableFindings[$tableName] = $this->findRedundantIndexes($indexes);
				}
				
				// Single flat table covering every index across all tables
				$this->renderIndexTable($tables, $tableIndexes, $tableFindings, $usageStats, $usageAvailable, $dbType);
				
				$this->output->writeLn("");
				
				// Note usage stats availability
				if (!$usageAvailable && in_array($dbType, ['mysql', 'mariadb', 'pgsql'], true)) {
					$this->output->writeLn("Note: index usage statistics could not be retrieved. On MySQL/MariaDB, ensure performance_schema is enabled.");
					$this->output->writeLn("      To enable it, add 'performance_schema = ON' to your my.cnf under [mysqld] and restart the server.");
				} elseif ($dbType === 'sqlite') {
					$this->output->writeLn("Note: SQLite does not expose index usage statistics; the Reads/Writes columns are omitted.");
				}
				
				return 0;
				
			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
		
		// ==================== Redundancy Detection ====================
		
		/**
		 * Identifies redundant indexes within a single table's index map.
		 *
		 * An index is considered redundant when:
		 *   - Another index covers the exact same column list in the same order, OR
		 *   - Another index starts with the same columns (prefix coverage), making
		 *     the narrower index entirely superseded for equality and range lookups.
		 *
		 * Primary key indexes are excluded from consideration: they carry structural
		 * significance (constraint enforcement, clustered storage on InnoDB) that is
		 * independent of query planning, so they must never be dropped.
		 *
		 * When two indexes are exact duplicates the one that appears later in the
		 * sorted index list is flagged, giving the caller a stable, deterministic
		 * result regardless of the order the database returns the index names.
		 *
		 * @param array<string, array{type: string, columns: string[], length: array<int,int>|null}> $indexes
		 *   Index definitions keyed by index name, as returned by DatabaseAdapter::getIndexes().
		 * @return array<int, array{
		 *     redundant_index: string,
		 *     redundant_columns: string[],
		 *     covered_by: string,
		 *     covered_columns: string[],
		 *     reason: string
		 * }> List of redundancy findings. Empty when no redundancy is detected.
		 */
		private function findRedundantIndexes(array $indexes): array {
			// Primary keys have structural significance beyond query planning;
			// they must never be suggested for removal.
			$candidates = array_filter($indexes, fn($idx) => $idx['type'] !== 'primary');
			
			// Sort by index name for deterministic output when two indexes are
			// exact duplicates — the one that sorts later is flagged.
			ksort($candidates);
			
			$findings  = [];
			$names     = array_keys($candidates);
			$processed = [];
			
			foreach ($names as $i => $nameA) {
				// Skip if this index was already flagged as redundant
				if (isset($processed[$nameA])) {
					continue;
				}
				
				$colsA = $candidates[$nameA]['columns'];
				
				for ($j = $i + 1; $j < count($names); $j++) {
					$nameB = $names[$j];
					
					if (isset($processed[$nameB])) {
						continue;
					}
					
					$colsB = $candidates[$nameB]['columns'];
					
					// Case 1: exact duplicate — identical column list in identical order.
					// Flag the later index (nameB, which sorts after nameA).
					if ($colsA === $colsB) {
						$findings[]       = [
							'redundant_index'   => $nameB,
							'redundant_columns' => $colsB,
							'covered_by'        => $nameA,
							'covered_columns'   => $colsA,
							'reason'            => 'Exact duplicate',
						];
						$processed[$nameB] = true;
						continue;
					}
					
					// Case 2: prefix redundancy.
					// If colsA is a strict prefix of colsB, then nameA is redundant
					// because nameB already handles every lookup nameA could serve.
					if ($this->isPrefix($colsA, $colsB)) {
						$findings[]       = [
							'redundant_index'   => $nameA,
							'redundant_columns' => $colsA,
							'covered_by'        => $nameB,
							'covered_columns'   => $colsB,
							'reason'            => 'Prefix of ' . $nameB,
						];
						$processed[$nameA] = true;
						break; // nameA is already flagged; move to the next outer index
					}
					
					// Mirror: if colsB is a strict prefix of colsA, then nameB is redundant.
					if ($this->isPrefix($colsB, $colsA)) {
						$findings[]       = [
							'redundant_index'   => $nameB,
							'redundant_columns' => $colsB,
							'covered_by'        => $nameA,
							'covered_columns'   => $colsA,
							'reason'            => 'Prefix of ' . $nameA,
						];
						$processed[$nameB] = true;
					}
				}
			}
			
			return $findings;
		}
		
		/**
		 * Returns true when $shorter is a strict prefix of $longer.
		 *
		 * "Strict" means $shorter must be shorter than $longer; two identical
		 * arrays are not considered a prefix relationship here — those are caught
		 * as exact duplicates before this method is called.
		 *
		 * @param string[] $shorter The candidate prefix column list
		 * @param string[] $longer  The column list that may start with $shorter
		 * @return bool
		 */
		private function isPrefix(array $shorter, array $longer): bool {
			if (count($shorter) >= count($longer)) {
				return false;
			}
			
			return array_slice($longer, 0, count($shorter)) === $shorter;
		}
		
		// ==================== Usage Statistics ====================
		
		/**
		 * Loads per-index read and write usage counters from the database engine.
		 *
		 * Returns a nested array keyed by table name then index name:
		 *   ['table' => ['index_name' => ['reads' => int, 'writes' => int]]]
		 *
		 * Returns null when:
		 *   - The driver is SQLite (no stats available at the engine level).
		 *   - The required system table is inaccessible (performance_schema disabled,
		 *     insufficient privileges, etc.).
		 *
		 * Callers must treat a null return as "stats unavailable" and omit the
		 * usage column from output rather than showing zeros, which would be
		 * misleading (zero hits and "no data" are different signals).
		 *
		 * @param \Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter
		 * @param string   $dbType  Normalised database type from getDatabaseType()
		 * @param string[] $tables  All table names in the database
		 * @return array<string, array<string, array{reads: int, writes: int}>>|null
		 */
		private function loadUsageStats(
			\Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter,
			string $dbType,
			array $tables
		): ?array {
			return match ($dbType) {
				'mysql', 'mariadb' => $this->loadMysqlUsageStats($databaseAdapter, $tables),
				'pgsql'            => $this->loadPostgresUsageStats($databaseAdapter, $tables),
				default            => null, // SQLite and any future drivers: no stats
			};
		}
		
		/**
		 * Loads index usage stats from MySQL/MariaDB performance_schema.
		 *
		 * Queries table_io_waits_summary_by_index_usage, which tracks the number
		 * of read and write I/O waits per index. A row with COUNT_READ = 0 and
		 * COUNT_WRITE = 0 indicates the index has never been accessed since the
		 * last server restart or TRUNCATE of the summary table.
		 *
		 * Table names are interpolated directly rather than bound as parameters.
		 * performance_schema tables reject prepared-statement parameter binding on
		 * some MySQL/MariaDB versions, causing the query to fail silently. Table
		 * names are sourced from getTables() (schema introspection, not user input)
		 * and are escaped via addslashes() before interpolation, so this is safe.
		 *
		 * Returns null when performance_schema is unavailable or the query fails,
		 * so the caller can distinguish "zero usage" from "no data".
		 *
		 * @param \Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter
		 * @param string[] $tables
		 * @return array<string, array<string, array{reads: int, writes: int}>>|null
		 */
		private function loadMysqlUsageStats(
			\Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter,
			array $tables
		): ?array {
			// Quote each table name and interpolate into the IN clause.
			// Binding via ? placeholders fails against performance_schema tables on
			// some MySQL/MariaDB versions; direct interpolation of schema-sourced,
			// quoted names is safe here.
			$inList = implode(', ', array_map(
				fn(string $t) => "'" . addslashes($t) . "'",
				$tables
			));
			
			$sql = "
				SELECT
					OBJECT_NAME   AS table_name,
					INDEX_NAME    AS index_name,
					COUNT_READ    AS `reads`,
					COUNT_WRITE   AS `writes`
				FROM performance_schema.table_io_waits_summary_by_index_usage
				WHERE OBJECT_SCHEMA = DATABASE()
				  AND OBJECT_NAME IN ({$inList})
				  AND INDEX_NAME IS NOT NULL
			";
			
			$statement = $databaseAdapter->execute($sql);
			
			if ($statement === null) {
				return null;
			}
			
			$result = [];
			
			/** @var array{table_name: string, index_name: string, reads: int, writes: int} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$result[$row['table_name']][$row['index_name']] = [
					'reads'  => (int)$row['reads'],
					'writes' => (int)$row['writes'],
				];
			}
			
			return $result;
		}
		
		/**
		 * Loads index usage stats from PostgreSQL pg_stat_user_indexes.
		 *
		 * idx_scan is the number of index scans initiated on this index.
		 * PostgreSQL does not expose a separate write counter at the index level
		 * (writes are tracked at the table level), so writes are reported as -1
		 * and rendered as n/a in output.
		 *
		 * Table names are interpolated directly for consistency with the MySQL
		 * implementation; they are sourced from getTables() and escaped before use.
		 *
		 * @param \Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter
		 * @param string[] $tables
		 * @return array<string, array<string, array{reads: int, writes: int}>>|null
		 */
		private function loadPostgresUsageStats(
			\Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $databaseAdapter,
			array $tables
		): ?array {
			$inList = implode(', ', array_map(
				fn(string $t) => "'" . addslashes($t) . "'",
				$tables
			));
			
			$sql = "
				SELECT
					relname      AS table_name,
					indexrelname AS index_name,
					idx_scan     AS reads
				FROM pg_stat_user_indexes
				WHERE relname IN ({$inList})
			";
			
			$statement = $databaseAdapter->execute($sql);
			
			if ($statement === null) {
				return null;
			}
			
			$result = [];
			
			/** @var array{table_name: string, index_name: string, reads: int} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$result[$row['table_name']][$row['index_name']] = [
					'reads'  => (int)$row['reads'],
					'writes' => -1, // PostgreSQL does not track per-index writes
				];
			}
			
			return $result;
		}
		
		// ==================== Output Rendering ====================
		
		/**
		 * Renders a single flat table with one row per index across all tables.
		 *
		 * Columns:
		 *   Table   : table name
		 *   Index   : index name
		 *   Type    : primary, unique, index, fulltext
		 *   Columns : comma-separated column list
		 *   Status  : "ok" or a description of the redundancy
		 *   Reads   : read hit count (omitted when stats unavailable)
		 *   Writes  : write hit count (omitted for PostgreSQL and when stats unavailable)
		 *
		 * Tables with no indexes produce no rows (they are silently skipped).
		 *
		 * @param string[]   $tables
		 * @param array<string, array<string, array{type: string, columns: string[], length: array<int,int>|null}>> $tableIndexes
		 * @param array<string, array<int, array{redundant_index: string, redundant_columns: string[], covered_by: string, covered_columns: string[], reason: string}>> $tableFindings
		 * @param array<string, array<string, array{reads: int, writes: int}>>|null $usageStats
		 * @param bool   $usageAvailable
		 * @param string $dbType
		 */
		private function renderIndexTable(
			array $tables,
			array $tableIndexes,
			array $tableFindings,
			?array $usageStats,
			bool $usageAvailable,
			string $dbType
		): void {
			$headers = ['Table', 'Index', 'Type', 'Columns', 'Status'];
			
			if ($usageAvailable) {
				$headers[] = 'Reads';
				
				// PostgreSQL does not track per-index writes; omit the column entirely
				// rather than filling every row with n/a.
				if ($dbType !== 'pgsql') {
					$headers[] = 'Writes';
				}
			}
			
			$rows = [];
			
			foreach ($tables as $tableName) {
				$indexes  = $tableIndexes[$tableName] ?? [];
				$findings = $tableFindings[$tableName] ?? [];
				
				if (empty($indexes)) {
					continue;
				}
				
				// Build a lookup of redundant index name => finding for O(1) access per row
				$redundantMap = [];
				
				foreach ($findings as $finding) {
					$redundantMap[$finding['redundant_index']] = $finding;
				}
				
				foreach ($indexes as $indexName => $index) {
					if (isset($redundantMap[$indexName])) {
						$f = $redundantMap[$indexName];
						
						if (str_starts_with($f['reason'], 'Exact duplicate')) {
							$status = "Duplicate of {$f['covered_by']}";
						} else {
							$status = "Prefix of {$f['covered_by']} (" . implode(', ', $f['covered_columns']) . ")";
						}
					} else {
						$status = "ok";
					}
					
					$row = [
						$tableName,
						$indexName,
						$index['type'],
						implode(', ', $index['columns']),
						$status,
					];
					
					if ($usageAvailable) {
						$stats = $usageStats[$tableName][$indexName] ?? null;
						$row[] = $stats !== null ? (string)$stats['reads'] : 'n/a';
						
						if ($dbType !== 'pgsql') {
							$row[] = ($stats !== null && $stats['writes'] >= 0) ? (string)$stats['writes'] : 'n/a';
						}
					}
					
					$rows[] = $row;
				}
			}
			
			if (empty($rows)) {
				$this->output->writeLn("No indexes found across " . count($tables) . " tables.");
				return;
			}
			
			$this->output->table($headers, $rows);
		}
	}