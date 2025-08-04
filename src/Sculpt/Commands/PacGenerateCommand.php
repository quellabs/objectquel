<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Sculpt\Helpers\PacComponentGenerator;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	
	/**
	 * PacGenerateCommand - CLI command for generating WakaPAC JavaScript abstractions from ObjectQuel entities
	 *
	 * This command creates JavaScript abstraction files that can be used with the WakaPAC library
	 * for reactive data binding and component management. The generated files include property
	 * mappings, utility methods, and factory functions based on the entity's column definitions.
	 */
	class PacGenerateCommand extends CommandBase {
		
		/**
		 * Get the command signature for CLI usage
		 * @return string The command signature
		 */
		public function getSignature(): string {
			return "make:pac-entity";
		}
		
		/**
		 * Get the command description shown in help
		 * @return string The command description
		 */
		public function getDescription(): string {
			return "Generate a WakaPAC JavaScript abstraction from an existing ObjectQuel entity";
		}
		
		/**
		 * Returns a help text
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Generate a WakaPAC JavaScript abstraction from an existing ObjectQuel entity.
    
    This command creates JavaScript abstraction files that provide reactive data binding
    and component management capabilities for your ObjectQuel entities. The generated
    files include property mappings, utility methods, and factory functions based on
    the entity's column definitions.

USAGE:
    php sculpt make:pac-entity [EntityName] [options]

ARGUMENTS:
    EntityName        The name of the entity class (without "Entity" suffix)
                      Example: AgreeableElephant (will look for AgreeableElephantEntity)
                      If not provided, you will be prompted to enter it

OPTIONS:
    --o,              Specify custom output path for the generated abstraction file
                      Default: public/js/abstractions/{EntityName}Abstraction.js

EXAMPLES:
    php sculpt make:pac-entity User
        Generates UserAbstraction.js from UserEntity in default location
    
    php sculpt make:pac-entity Product --o ./frontend/js/Product.js
        Generates Product.js from ProductEntity in custom location
    
    php sculpt make:pac-entity
        Interactive mode - prompts for entity name

NOTES:
    - The entity class must exist and follow ObjectQuel naming conventions
    - Output directory will be created automatically if it doesn't exist
    - Generated files are compatible with the WakaPAC library
HELP;
		}
		
		/**
		 * Execute the command to generate the PAC abstraction
		 * @param ConfigurationManager $config The configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				// Get the project root directory
				$rootDir = ComposerUtils::getProjectRoot();
				
				// Fetch entity Name from cli or ask for it if it's not given
				$entityName = $config->getPositional(0);

				if ($entityName === null) {
					// Prompt user for the entity name (without "Entity" suffix)
					$entityName = $this->input->ask("Class name of the entity to create (e.g. AgreeableElephant)");
					
					// If no entity name provided, exit gracefully
					if (empty($entityName)) {
						$this->output->writeLn("No entity name provided. Exiting.");
						return 0;
					}
				}
				
				// Determine the output path
				if ($config->get("o") !== null) {
					$outputPath = $config->get("o");
				} else {
					$outputPath = $rootDir . "/public/js/abstractions/{$entityName}Abstraction.js";
				}
				
				// Construct the full entity class name with "Entity" suffix
				$entityNamePlus = $entityName . "Entity";
				
				// Create the generator instance with the entity name and configuration
				$generator = new PacComponentGenerator($entityNamePlus, $this->provider->getConfiguration());
				
				// Ensure the output directory exists before writing
				$this->ensureDirectoryExists(dirname($outputPath));
				
				// Generate and write the JavaScript abstraction file
				file_put_contents($outputPath, $generator->create());
				
				// Inform user of successful generation
				$this->output->success("Generated PAC abstraction: {$outputPath}");
				
				return 0;
			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 0;
			}
		}
		
		/**
		 * Ensure the output directory exists, creating it if necessary
		 * @return void
		 */
		private function ensureDirectoryExists(string $directory): void {
			if (!is_dir($directory)) {
				mkdir($directory, 0755, true);
			}
		}
	}