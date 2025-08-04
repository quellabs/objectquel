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
		 * Execute the command to generate the PAC abstraction
		 * @param ConfigurationManager $config The configuration manager instance
		 * @return int Exit code (0 for success)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				// Get the project root directory
				$rootDir = ComposerUtils::getProjectRoot();
				
				// Prompt user for the entity name (without "Entity" suffix)
				$entityName = $this->input->ask("Class name of the entity to create (e.g. AgreeableElephant)");
				
				// If no entity name provided, exit gracefully
				if (empty($entityName)) {
					$this->output->writeLn("No entity name provided. Exiting.");
					return 0;
				}
				
				// Construct the full entity class name with "Entity" suffix
				$entityNamePlus = $entityName . "Entity";
				
				// Create the generator instance with the entity name and configuration
				$generator = new PacComponentGenerator($entityNamePlus, $this->provider->getConfiguration());
				
				// Ensure the output directory exists before writing
				$this->ensureDirectoryExists();
				
				// Generate and write the JavaScript abstraction file
				$outputPath = $rootDir . "/public/js/abstractions/{$entityName}Abstraction.js";
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
		private function ensureDirectoryExists(): void {
			$rootDir = ComposerUtils::getProjectRoot();
			$outputDir = $rootDir . "/public/js/abstractions/";
			
			// Create directory with proper permissions if it doesn't exist
			if (!is_dir($outputDir)) {
				mkdir($outputDir, 0755, true);
			}
		}
	}