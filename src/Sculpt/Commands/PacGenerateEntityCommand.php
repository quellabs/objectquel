<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PacCanvasControllerGenerator;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PacJSGenerator;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Support\FrameworkResolver;
	
	/**
	 * PacGenerateCommand - CLI command for generating WakaPAC JavaScript abstractions from ObjectQuel entities
	 *
	 * This command creates JavaScript abstraction files that can be used with the WakaPAC library
	 * for reactive data binding and component management. The generated files include property
	 * mappings, utility methods, and factory functions based on the entity's column definitions.
	 */
	class PacGenerateEntityCommand extends MakeCommandBase {
		
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
				// Get the entity name from configuration or user input
				$entityName = $this->getEntityName($config);
				
				// Check if entity name was provided - exit gracefully if not
				if (empty($entityName)) {
					$this->output->writeLn("No entity name provided. Exiting.");
					return 0;
				}
				
				// Resolve the actual registered entity class name — no suffix assumed
				$fullEntityName = $this->resolveEntityClassName($entityName);
				
				if ($fullEntityName === null) {
					$this->output->writeLn("Entity '{$entityName}' does not exist.");
					$this->output->writeLn("Available entities can be listed with: php sculpt list:entities");
					return 1;
				}
				
				// Derive a clean base name from the resolved class name so that
				// file and controller names are consistent regardless of user input
				$baseName = $this->deriveBaseName($fullEntityName);
				
				// Determine the output path for the generated JavaScript abstraction files
				$jsOutputPath = $this->getJavaScriptOutputPath($config, $baseName);
				$this->generateJavaScriptAbstraction($fullEntityName, $jsOutputPath);
				$this->generateControllerIfFrameworkDetected($baseName, $fullEntityName);
				
				return 0;
				
			} catch (\Exception $e) {
				// Log any errors that occurred during execution
				$this->output->error($e->getMessage());
				return 1;
			}
		}
		
		/**
		 * Get entity name from config or prompt user
		 * @param ConfigurationManager $config
		 * @return string|null
		 */
		private function getEntityName(ConfigurationManager $config): ?string {
			// First, try to get the entity name from the first positional argument
			$entityName = $config->getPositional(0);
			
			// If entity name was provided as a command line argument, return it
			if (is_string($entityName) && $entityName !== "") {
				return $entityName;
			}
			
			// If no entity name was provided, prompt the user interactively
			// Note: The prompt asks for just the class name without "Entity" suffix
			// Example: User would enter "AgreeableElephant" not "AgreeableElephantEntity"
			return $this->input->ask("Class name of the entity to create (e.g. AgreeableElephant)");
		}
		
		/**
		 * Determine the JavaScript output path
		 * @param ConfigurationManager $config
		 * @param string $entityName
		 * @return string
		 */
		private function getJavaScriptOutputPath(ConfigurationManager $config, string $entityName): string {
			// Check if a custom output path was explicitly provided via the "o" option
			if ($config->getAsString("o") !== '') {
				return $config->getAsString("o");
			}
			
			// If no custom path specified, get the provider configuration to build default path
			$config = $this->getProvider()->getConfig();
			
			// Get the project root directory from Composer
			$rootDir = ComposerUtils::getProjectRoot();
			
			// Use configured public directory or default to "public"
			$publicDir = $config["public_directory"] ?? "public";
			
			// Build and return the default abstraction file path
			// Format: {projectRoot}/{publicDir}/js/abstractions/{EntityName}Abstraction.js
			return "{$rootDir}/{$publicDir}/js/abstractions/{$entityName}Abstraction.js";
		}
		
		/**
		 * Generate the JavaScript abstraction file
		 * @param string $fullEntityName
		 * @param string $outputPath
		 * @throws EntityResolutionException
		 * @throws \Exception
		 */
		private function generateJavaScriptAbstraction(string $fullEntityName, string $outputPath): void {
			// Initialize the entity store for data access operations
			$entityStore = $this->getEntityStore();
			
			// Create the generator instance with the entity name and configuration
			$jsGenerator = new PacJSGenerator($fullEntityName, $entityStore);
			
			// Ensure the output directory exists before writing
			$this->ensureDirectoryExists(dirname($outputPath));
			
			// Generate and write the JavaScript abstraction file
			file_put_contents($outputPath, $jsGenerator->create());
			
			// Inform user of successful generation
			$this->output->success("Generated PAC abstraction: {$outputPath}");
		}
		
		/**
		 * Generate controller if a supported framework is detected
		 * @param string $entityName
		 * @param string $fullEntityName
		 * @throws EntityResolutionException
		 */
		private function generateControllerIfFrameworkDetected(string $entityName, string $fullEntityName): void {
			$framework = FrameworkResolver::detect();
			
			if ($framework !== 'canvas') {
				$this->output->warning("Skipped generating PAC controller because no framework detected");
				return;
			}
			
			// Initialize the entity store for data access operations
			$entityStore = $this->getEntityStore();
			$outputPath = ComposerUtils::getProjectRoot() . "/src/Controllers/Pac{$entityName}Controller.php";
			$phpGenerator = new PacCanvasControllerGenerator($fullEntityName, $entityStore);
			
			// Generate and write the controller file
			file_put_contents($outputPath, $phpGenerator->create());
			
			// Inform user of successful generation
			$this->output->success("Generated PAC controller: {$outputPath}");
		}

	}