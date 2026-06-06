<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	
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
		 * Returns a help text
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Initialize ObjectQuel configuration files in your project.

    Creates the config/ directory if it does not exist, then writes a
    database.php template that you can edit to match your environment.
    If database.php already exists it is left untouched.

USAGE:
    php sculpt quel:init

EXAMPLES:
    php sculpt quel:init
        Creates config/database.php with a pre-filled template and prints
        next-step instructions for configuring your database connection.
HELP;
		}
		
		/**
		 * Execute the configuration initialization process
		 *
		 * This method:
		 * 1. Determines the appropriate project root directory
		 * 2. Creates the config directory if it doesn't exist
		 * 3. Writes template configuration files to the project
		 * 4. Provides clear next-step instructions to the user
		 *
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			// Show an introductory message
			$this->output->writeLn("");
			$this->output->writeLn(" ██████╗ ██╗   ██╗███████╗██╗");
			$this->output->writeLn("██╔═══██╗██║   ██║██╔════╝██║");
			$this->output->writeLn("██║   ██║██║   ██║█████╗  ██║");
			$this->output->writeLn("██║▄▄ ██║██║   ██║██╔══╝  ██║");
			$this->output->writeLn("╚██████╔╝╚██████╔╝███████╗███████╗");
			$this->output->writeLn(" ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝");
			$this->output->writeLn("");
			$this->output->writeLn("Initializing ObjectQuel configuration...");
			
			// Determine the target directory - project root is preferable
			$configDir = ComposerUtils::getProjectRoot() . "/config";
			
			// Create config directory if it doesn't already exist
			if (!is_dir($configDir)) {
				if (!mkdir($configDir, 0755, true)) {
					$this->output->error("Failed to create config directory: {$configDir}");
					$this->output->writeLn("Please check directory permissions and try again.");
					return 1;
				}
				
				$this->output->writeLn("Created config directory");
			}
			
			// Write database configuration template
			$targetPath = $configDir . "/database.php";
			
			if (file_exists($targetPath)) {
				$this->output->warning("File already exists: database.php (skipped)");
				return 0;
			}
			
			if (file_put_contents($targetPath, $this->getDatabaseTemplate()) === false) {
				$this->output->error("Failed to write database.php");
				$this->output->writeLn("");
				$this->output->error("Configuration initialization completed with errors.");
				$this->output->writeLn("Please check file permissions and template availability.");
				return 1;
			}
			
			$this->output->success("Created database.php - Main database configuration");
			$this->output->success("ObjectQuel configuration initialized successfully!");
			$this->output->writeLn("");
			$this->output->writeLn("Configuration files created in: {$configDir}");
			$this->output->writeLn("");
			$this->output->writeLn("Next steps:");
			$this->output->writeLn("1. Edit config/database.php to configure your database connection");
			$this->output->writeLn("2. Run 'php sculpt make:entity' to create your first entity");
			return 0;
		}
		
		/**
		 * Returns the database configuration template content
		 * @return string The database.php template content
		 */
		private function getDatabaseTemplate(): string {
			return <<<'PHP'
<?php

	return [
		'driver'           => 'mysql',                  // Database driver (mysql, postgresql, sqlite, etc.)
		'host'             => 'localhost',              // Database server hostname or IP address
		'database'         => '',                       // Name of the database to connect to
		'username'         => '',                       // Database username for authentication
		'password'         => '',                       // Database password for authentication
		'port'             => 3306,                     // Database server port (3306 is MySQL default)
		'charset'          => 'utf8mb4',                // Character set for database connection
		'collation'        => 'utf8mb4_unicode_ci',     // Collation for text comparison and sorting
		
		// Entity namespace
		'entity_namespace' => 'App\\Entities',
		
		// Path to the entities folder
		'entity_path'      => dirname(__FILE__) . '/../src/Entities/',
		
		// Path to the proxy folder
		'proxy_path'       => dirname(__FILE__) . '/../storage/objectquel/proxies/',
		
		// Path to the migrations folder
		'migrations_path'  => dirname(__FILE__) . '/../migrations',
	];
PHP;
		}
	}