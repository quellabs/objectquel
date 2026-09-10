<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * QuelIndexShowCommand - Makes a database index visible to the query optimizer
	 *
	 * Reverses the effect of quel:index-hide by marking an index as visible
	 * (MySQL 8.0+) or not-ignored (MariaDB), allowing the optimizer to consider
	 * it again when planning queries.
	 *
	 * Supported dialects: MySQL 8.0+, MariaDB.
	 */
	class QuelIndexShowCommand extends MakeCommandBase {
		
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
DESCRIPTION:
    Makes a previously hidden database index visible to the query optimizer again.

USAGE:
    php sculpt quel:index-show  [entity] [index]

ARGUMENTS:
    entity    Optional entity class name. If omitted, you will be prompted.
    index     Optional index name. If omitted, you will be prompted.

EXAMPLES:
    php sculpt quel:index-show User idx_email
    php sculpt quel:index-show OrderLine idx_created_at

NOTES:
    - Only supported on MySQL 8.0+ and MariaDB
    - PostgreSQL does not support invisible indexes
HELP;
		}

		/**
		 * Execute the show-index command.
		 *
		 * Workflow:
		 *   1. Resolve entity name and index name (from args or interactive prompt).
		 *   2. Verify the entity exists in the store and resolve it to a table name.
		 *   3. Run the equivalent `show Name on Table` ObjectQuel statement — a
		 *      nonexistent index or table surfaces as a QuelException from the
		 *      DDL itself, no separate pre-check needed.
		 *
		 * @param ConfigurationManager $config Provides access to CLI arguments
		 * @return int 0 on success, 1 on any error
		 * @throws EntityResolutionException
		 */
		public function execute(ConfigurationManager $config): int {
			// Ask for entity name
			$entityName = $config->getPositional(0);
			
			if ($entityName === null) {
				$entityName = $this->collectIdentifier("Entity name");
			} elseif (!$this->isValidPhpIdentifier($entityName)) {
				$this->output->error("Invalid entity name '{$entityName}'.");
				return 1;
			}
			
			// Resolve the actual registered entity class name — no suffix assumed
			$fullEntityName = $this->resolveEntityClassName($entityName);
			
			if ($fullEntityName === null) {
				$this->output->writeLn("Entity '{$entityName}' does not exist.");
				$this->output->writeLn("Available entities can be listed with: php sculpt list:entities");
				return 1;
			}
			
			// Ask for index name
			$indexName = $config->getPositional(1);
			
			if ($indexName === null) {
				$indexName = $this->collectIdentifier("Entity name");
			} elseif (!$this->isValidPhpIdentifier($indexName)) {
				$this->output->error("Invalid index name '{$indexName}'.");
				return 1;
			}

			// Translate the entity class name to its underlying database table
			/** @var ServiceProvider $provider */
			$provider = $this->provider;
			$metadata = $this->getEntityStore()->getMetadata($fullEntityName);

			// Run the actual ObjectQuel statement — same executeQuery() entry
			// point any other caller uses, not a hand-built ALTER TABLE string.
			// A nonexistent index/table or an unsupported dialect surfaces here
			// as a QuelException; no separate existence/support pre-check.
			try {
				$provider->getEntityManager()->executeQuery("show {$indexName} on {$metadata->tableName}");
			} catch (QuelException $e) {
				$this->output->error($e->getMessage());
				return 1;
			}

			// Success
			$this->output->success("Index '{$indexName}' on table '{$metadata->tableName}' is now visible to the optimizer.");
			return 0;
		}
	}