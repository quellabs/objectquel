<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\Sculpt\ConfigurationManager;
	
	/**
	 * ListEntitiesCommand - CLI command for listing all registered ObjectQuel entities
	 *
	 * Displays a table of every entity class discovered by the EntityStore, showing
	 * the fully-qualified class name and the database table it maps to.
	 */
	class ListEntitiesCommand extends MakeCommandBase {
		
		/**
		 * Get the command signature for CLI usage
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "list:entities";
		}
		
		/**
		 * Get the command description shown in help
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "List all registered ObjectQuel entities and their mapped database tables";
		}
		
		/**
		 * Returns a help text
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    List all entity classes that ObjectQuel has discovered and registered.

    Each row shows the fully-qualified class name and the database table it maps
    to via the @Table annotation. Only classes with a @Table annotation are shown;
    unmapped classes are silently ignored by the EntityStore.

USAGE:
    php sculpt list:entities

ARGUMENTS:
    None
HELP;
		}
		
		/**
		 * Execute the command to list all registered entities
		 * @param ConfigurationManager $config The configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				$entityMap = $this->getEntityStore()->getEntityMap();
				
				if (empty($entityMap)) {
					$this->output->writeLn("No entities found.");
					return 0;
				}
				
				// Sort by class name for predictable, readable output
				ksort($entityMap);
				
				// Build rows from the className => tableName map
				$rows = [];
				
				foreach ($entityMap as $className => $tableName) {
					$rows[] = [$className, $tableName];
				}
				
				$this->output->table(['Entity Class', 'Table'], $rows);
				$this->output->writeLn(count($rows) . " " . (count($rows) === 1 ? "entity" : "entities") . " registered.");
				return 0;
				
			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}