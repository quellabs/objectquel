<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * QuelIndexShowCommand - Makes a database index visible to the query optimizer
	 *
	 * Reverses the effect of quel:index-hide by marking an index as visible
	 * (MySQL 8.0+) or not-ignored (MariaDB), allowing the optimizer to consider
	 * it again when planning queries.
	 *
	 * Supported dialects: MySQL 8.0+, MariaDB.
	 */
	class QuelIndexShowCommand extends CommandBase {
		
		/** @var EntityStore|null Lazy-loaded entity store; populated on first access */
		private ?EntityStore $entityStore = null;
		
		/** @var Configuration ORM configuration passed in via the service provider */
		private Configuration $configuration;
		
		/**
		 * Constructor
		 * @param ConsoleInput         $input    Console input handler
		 * @param ConsoleOutput        $output   Console output handler
		 * @param ServiceProvider|null $provider Service provider exposing configuration and the DB adapter
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ServiceProvider $provider = null) {
			parent::__construct($input, $output, $provider);
			
			// Pull configuration out of the provider so execute() can instantiate EntityStore
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Execute the show-index command.
		 *
		 * Workflow:
		 *   1. Resolve entity name and index name (from args or interactive prompt).
		 *   2. Verify the entity exists in the store and resolve it to a table name.
		 *   3. Confirm the target database dialect supports invisible indexes.
		 *   4. Confirm the named index exists on that table.
		 *   5. Issue the dialect-appropriate ALTER TABLE DDL.
		 *
		 * @param ConfigurationManager $config Provides access to CLI arguments
		 * @return int 0 on success, 1 on any error
		 */
		public function execute(ConfigurationManager $config): int {
			// Prefer positional CLI arguments; fall back to interactive prompts
			$entityName = $config->getPositional(0);
			$indexName  = $config->getPositional(1);
			
			if (empty($entityName)) {
				$entityName = $this->input->ask("Entity name");
			}
			
			if (empty($indexName)) {
				$indexName = $this->input->ask("Index name");
			}
			
			// Both values are required; exit cleanly if the user provides neither
			if (empty($entityName) || empty($indexName)) {
				return 0;
			}
			
			// Ensure the entity is registered in the ORM metadata store
			$entityStore = $this->getEntityStore();
			
			if (!$entityStore->exists($entityName)) {
				$this->output->error("Entity '{$entityName}' does not exist.");
				return 1;
			}
			
			// Translate the entity class name to its underlying database table
			$tableName = $entityStore->getOwningTable($entityName);
			
			$databaseAdapter = $this->provider->getDatabaseAdapter();
			$dbType          = $databaseAdapter->getDatabaseType();
			
			// Invisible indexes are a MySQL/MariaDB-only feature
			if (!in_array($dbType, ['mysql', 'mariadb'])) {
				$this->output->error("Invisible indexes are not supported by {$dbType}.");
				return 1;
			}
			
			// MySQL added invisible index support in 8.0; reject older versions early
			if ($dbType === 'mysql') {
				$version = $databaseAdapter->getMysqlVersion();
				
				if (version_compare($version, '8.0.0', '<')) {
					$this->output->error("Invisible indexes require MySQL 8.0.0 or higher (connected: {$version}).");
					return 1;
				}
			}
			
			// Guard against typos: confirm the index actually exists before issuing DDL
			$indexes = $databaseAdapter->getIndexes($tableName);
			
			if (!array_key_exists($indexName, $indexes)) {
				$this->output->error("Index '{$indexName}' does not exist on table '{$tableName}'.");
				return 1;
			}
			
			// Each dialect uses different syntax to restore an index to optimizer visibility:
			//   MySQL   → VISIBLE      (standard SQL extension)
			//   MariaDB → NOT IGNORED  (MariaDB-specific terminology)
			$sql = match($dbType) {
				'mysql'   => "ALTER TABLE `{$tableName}` ALTER INDEX `{$indexName}` VISIBLE",
				'mariadb' => "ALTER TABLE `{$tableName}` ALTER INDEX `{$indexName}` NOT IGNORED",
			};
			
			$result = $databaseAdapter->execute($sql);
			
			if ($result === false) {
				$this->output->error("Failed to make index visible: " . $databaseAdapter->getLastErrorMessage());
				return 1;
			}
			
			$this->output->success("Index '{$indexName}' on table '{$tableName}' is now visible to the optimizer.");
			return 0;
		}
		
		/**
		 * Returns the Sculpt command signature used to invoke this command.
		 * @return string
		 */
		public function getSignature(): string {
			return "quel:index-show";
		}
		
		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "Makes a database index visible to the query optimizer.";
		}
		
		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
Makes a previously hidden database index visible to the query optimizer.

Usage:
  quel:index-show <entity> <index>

Arguments:
  entity   The entity class name (e.g. User, OrderLine)
  index    The name of the index to make visible

Examples:
  vendor/bin/sculpt quel:index-show User idx_email
  vendor/bin/sculpt quel:index-show OrderLine idx_created_at

Notes:
  Only supported on MySQL 8.0+ and MariaDB.
  PostgreSQL does not support invisible indexes.
HELP;
		}
		
		/**
		 * Lazy-loads and caches the EntityStore instance.
		 * @return EntityStore
		 */
		private function getEntityStore(): EntityStore {
			return $this->entityStore ??= new EntityStore($this->configuration);
		}
	}