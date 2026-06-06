<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * QuelIndexHideCommand - Hides a database index from the query optimizer
	 *
	 * Marks an index as invisible (MySQL 8.0+) or ignored (MariaDB), so the
	 * optimizer will not use it when planning queries. The index continues to
	 * be maintained by the engine, making this a safe way to test index removal
	 * before committing to a DROP INDEX.
	 *
	 * Supported dialects: MySQL 8.0+, MariaDB.
	 */
	class QuelIndexHideCommand extends MakeCommandBase {
		
		/** @var EntityStore|null Lazy-loaded entity store; populated on first access */
		private ?EntityStore $entityStore = null;
		
		/**
		 * Constructor
		 * @param ConsoleInput $input Console input handler
		 * @param ConsoleOutput $output Console output handler
		 * @param ServiceProvider $provider Service provider exposing configuration and the DB adapter
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			
			// Pull configuration out of the provider so execute() can instantiate EntityStore
			$this->configuration = $provider->getConfiguration();
		}
		
		
		/**
		 * Returns the Sculpt command signature used to invoke this command.
		 * @return string
		 */
		public function getSignature(): string {
			return "quel:index-hide";
		}
		
		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "Hides a database index from the query optimizer.";
		}
		
		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Hides a database index from the query optimizer without dropping it.

    The index continues to be maintained, so it can be made visible again with
    quel:index-show at any time. Use this to safely evaluate the impact of
    removing an index before committing to a permanent DROP INDEX.

USAGE:
    php sculpt quel:index-hide

NOTES:
    - Only supported on MySQL 8.0+ and MariaDB
    - PostgreSQL does not support invisible indexes
HELP;
		}

		/**
		 * Execute the hide-index command.
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
		 * @throws EntityResolutionException
		 */
		public function execute(ConfigurationManager $config): int {
			// Ask for entity name
			$entityName = $this->collectIdentifier("Enter the entity name (e.g. User, UserEntity, Product)");
			
			// Resolve the actual registered entity class name — no suffix assumed
			$fullEntityName = $this->resolveEntityClassName($entityName);
			
			if ($fullEntityName === null) {
				$this->output->writeLn("Entity '{$entityName}' does not exist.");
				$this->output->writeLn("Available entities can be listed with: php sculpt list:entities");
				return 1;
			}
			
			// Ask for index name
			$indexName = $this->collectIdentifier("Index name");
			
			// Translate the entity class name to its underlying database table
			/** @var ServiceProvider $provider */
			$provider = $this->provider;
			$databaseAdapter = $provider->getDatabaseAdapter();
			$capabilities = new PlatformCapabilities($databaseAdapter);
			
			// Invisible indexes are a MySQL/MariaDB-only feature
			if (!$capabilities->supportsIndexHiding()) {
				$this->output->error("Invisible indexes are not supported.");
				return 1;
			}
			
			// Guard against typos: confirm the index actually exists before issuing DDL
			$entityStore = $this->getEntityStore();
			$metadata = $entityStore->getMetadata($fullEntityName);
			$indexes = $databaseAdapter->getIndexes($metadata->tableName);
			
			if (!array_key_exists($indexName, $indexes)) {
				$this->output->error("Index '{$indexName}' does not exist on table '{$metadata->tableName}'.");
				return 1;
			}
			
			// Each dialect uses different syntax to hide an index from the optimizer:
			//   MySQL   → INVISIBLE  (standard SQL extension)
			//   MariaDB → IGNORED    (MariaDB-specific terminology)
			if ($databaseAdapter->getDatabaseType() === 'mysql') {
				$sql = "ALTER TABLE `{$metadata->tableName}` ALTER INDEX `{$indexName}` INVISIBLE";
			} else {
				$sql = "ALTER TABLE `{$metadata->tableName}` ALTER INDEX `{$indexName}` IGNORED";
			}
			
			// Execute the query
			$result = $databaseAdapter->execute($sql);
			
			// If the call failed, output an error
			if ($result === null) {
				$this->output->error("Failed to hide index: " . $databaseAdapter->getLastErrorMessage());
				return 1;
			}
			
			// Success!
			$this->output->success("Index '{$indexName}' on table '{$metadata->tableName}' is now hidden from the optimizer.");
			return 0;
		}
	}