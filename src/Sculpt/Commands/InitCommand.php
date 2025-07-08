<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\Discover\Discover;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	
	/**
	 * InitCommand - Initialize ObjectQuel configuration in your project
	 *
	 * This command sets up the essential configuration files needed to use ObjectQuel
	 * in your project. It creates database configuration templates that you can customize
	 * according to your specific database connection requirements.
	 *
	 * The command generates:
	 * - config/database.php: Main database configuration file
	 *
	 * These files provide the foundation for ObjectQuel's entity management, migrations,
	 * and query operations while maintaining consistency across different environments.
	 */
	class InitCommand extends CommandBase {
		
		/**
		 * @var Discover Discovery component
		 */
		private Discover $discover;
		
		/**
		 * InitCommand constructor
		 * @param ConsoleInput $input
		 * @param ConsoleOutput $output
		 * @param ProviderInterface|null $provider
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ?ProviderInterface $provider = null) {
			parent::__construct($input, $output, $provider);
			$this->discover = new Discover();
		}
		
		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature used to invoke this command
		 */
		public function getSignature(): string {
			return "quel:init";
		}
		
		/**
		 * Get a descriptive summary of what this command accomplishes
		 * @return string Brief description for help output
		 */
		public function getDescription(): string {
			return "Initialize ObjectQuel configuration files in your project";
		}
		
		/**
		 * Execute the configuration initialization process
		 *
		 * This method:
		 * 1. Determines the appropriate project root directory
		 * 2. Creates the config directory if it doesn't exist
		 * 3. Copies template configuration files to the project
		 * 4. Provides clear next-step instructions to the user
		 *
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			// Show an introductory message
			$this->output->writeLn("");
			$this->output->writeLn("Initializing ObjectQuel configuration...");
			
			// Determine the target directory - project root is preferable
			$projectRoot = $this->discover->getProjectRoot();
			$configDir = $projectRoot . "/config";
			
			// Create config directory if it doesn't already exist
			if (!is_dir($configDir)) {
				if (!mkdir($configDir, 0755, true)) {
					$this->output->error("Failed to create config directory: {$configDir}");
					$this->output->writeLn("Please check directory permissions and try again.");
					return 1;
				}
				
				$this->output->writeLn("Created config directory");
			}
			
			// Copy database configuration templates
			$success = true;
			$templateDir = dirname(__FILE__) . "/../../../config";
			
			$filesToCopy = [
				'database.php' => 'Main database configuration',
			];
			
			foreach ($filesToCopy as $filename => $description) {
				$targetPath = $configDir . "/" . $filename;
				$sourcePath = $templateDir . "/" . $filename;
				
				if (file_exists($targetPath)) {
					$this->output->warning("File already exists: {$filename} (skipped)");
					continue;
				}
				
				if (!file_exists($sourcePath)) {
					$this->output->error("Template file not found: {$sourcePath}");
					$success = false;
					continue;
				}
				
				if (@copy($sourcePath, $targetPath)) {
					$this->output->success("Created {$filename} - {$description}");
				} else {
					$this->output->error("Failed to copy {$filename}");
					$success = false;
				}
			}
			
			if (!$success) {
				$this->output->writeLn("");
				$this->output->error("Configuration initialization completed with errors.");
				$this->output->writeLn("Please check file permissions and template availability.");
				return 1;
			}
			
			// Provide clear success feedback and next steps
			$this->output->success("ObjectQuel configuration initialized successfully!");
			$this->output->writeLn("");
			$this->output->writeLn("📁 Configuration files created in: {$configDir}");
			$this->output->writeLn("");
			$this->output->writeLn("Next steps:");
			$this->output->writeLn("1. Edit config/database.php to configure your database connection");
			$this->output->writeLn("2. Run 'php sculpt make:entity' to create your first entity");
			
			return 0; // Success
		}
	}