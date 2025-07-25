<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	
	/**
	 * QuelCreatePhinxConfigCommand - CLI command for generating Phinx configuration
	 *
	 * This command creates a Phinx configuration file (phinx.php) that allows users
	 * to directly use Phinx's command-line tools for advanced migration operations
	 * that are not available through the ObjectQuel migration wrapper.
	 *
	 * The generated configuration file will use the same database connection
	 * settings as the application, ensuring consistency between direct Phinx usage
	 * and the ObjectQuel wrapper.
	 */
	class QuelCreatePhinxConfigCommand extends CommandBase {
		
		/**
		 * Execute the command
		 * @param ConfigurationManager $config Configuration manager instance
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				// Fetch the Phinx configuration array
				$phinxConfig = $this->getProvider()->createPhinxConfig();
				
				// Determine the target directory - project root is preferable
				$filePath = $this->determineProjectRoot() . '/phinx.php';
				
				// Format the config array as PHP code
				$fileContent = "<?php\n\n/**\n * Phinx Configuration File\n *\n * This file was auto-generated by the ObjectQuel migration system.\n * It allows direct use of Phinx commands for advanced migration features.\n *\n * @see https://book.cakephp.org/phinx/0/en/commands.html\n */\n\nreturn " . $this->varExport($phinxConfig) . ";\n";
				
				// Write to phinx.php in the project root
				$result = file_put_contents($filePath, $fileContent);
				
				if ($result === false) {
					throw new \RuntimeException("Failed to write configuration file to {$filePath}");
				}
				
				// Provide success feedback to the user
				$this->output->writeLn("");
				$this->output->success("Phinx configuration file created at: {$filePath}");
				$this->output->writeLn("");
				$this->output->writeLn("You can now use Phinx directly with commands like:");
				$this->output->writeLn("  vendor/bin/phinx status");
				$this->output->writeLn("  vendor/bin/phinx migrate");
				$this->output->writeLn("  vendor/bin/phinx rollback");
				$this->output->writeLn("  vendor/bin/phinx seed:run");
				
				return 0; // Success
			} catch (\Exception $e) {
				$this->output->error("Failed to create Phinx configuration: " . $e->getMessage());
				return 1; // Error
			}
		}

		/**
		 * Get the command signature/name for registration in the CLI
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return "quel:export-phinx";
		}
		
		/**
		 * Get a short description of what the command does
		 * @return string Command description
		 */
		public function getDescription(): string {
			return "Generate a Phinx configuration file for direct use with Phinx commands";
		}
		
		/**
		 * Get detailed help information for the command
		 * @return string Command help text
		 */
		public function getHelp(): string {
			return <<<EOT
The <info>quel:export-phinx</info> command generates a Phinx configuration file that allows
you to use Phinx's command-line tools directly for advanced migration features.

<comment>Why use this command:</comment>
  * Access advanced Phinx features not available through the ObjectQuel wrapper
  * Use database seeding functionality
  * Set migration breakpoints for more granular control
  * Run Phinx-specific commands not exposed by the ObjectQuel wrapper

<comment>Example usage:</comment>
  1. Generate the configuration file:
     <info>php bin/sculpt quel:export-phinx</info>
  
  2. Use Phinx commands directly:
     <info>vendor/bin/phinx status</info>
     <info>vendor/bin/phinx seed:create UserSeeder</info>
     <info>vendor/bin/phinx seed:run</info>

For more information on Phinx commands, see:
<href=https://book.cakephp.org/phinx/0/en/commands.html>https://book.cakephp.org/phinx/0/en/commands.html</href>
EOT;
		}
		
		/**
		 * Improved var_export with proper indentation and modern array syntax
		 * This method formats PHP arrays in a more readable way than the standard
		 * var_export function, using modern short array syntax and proper indentation.
		 * @param mixed $var The variable to export
		 * @return string|null Formatted PHP code or null if $return is false
		 */
		private function varExport(mixed $var): ?string {
			// For simple types, use standard var_export
			if (!is_array($var)) {
				return var_export($var, true);
			}
			
			// For arrays, build a custom-formatted string
			$output = '';
			$this->buildArrayExport($var, $output);
			return $output;
		}
		
		/**
		 * Recursively build formatted array export string
		 * @param array $array The array to export
		 * @param string &$output The output string being built
		 * @param int $depth Current nesting level
		 * @return void
		 */
		private function buildArrayExport(array $array, string &$output, int $depth = 0): void {
			$indent = str_repeat('    ', $depth);
			$output .= '[' . PHP_EOL;
			
			$last = count($array) - 1;
			$i = 0;
			
			foreach ($array as $key => $value) {
				$terminator = ($i < $last) ? ',' : '';
				$output .= $indent . '    ';
				
				// Format the key
				if (is_string($key)) {
					$output .= "'" . addslashes($key) . "' => ";
				} else {
					$output .= $key . ' => ';
				}
				
				// Format the value based on its type
				if (is_array($value)) {
					$this->buildArrayExport($value, $output, $depth + 1);
					$output .= $terminator . PHP_EOL;
				} elseif (is_null($value)) {
					$output .= 'null' . $terminator . PHP_EOL;
				} elseif (is_bool($value)) {
					$output .= ($value ? 'true' : 'false') . $terminator . PHP_EOL;
				} elseif (is_int($value) || is_float($value)) {
					$output .= $value . $terminator . PHP_EOL;
				} else {
					$output .= "'" . addslashes($value) . "'" . $terminator . PHP_EOL;
				}
				
				$i++;
			}
			
			$output .= $indent . ']';
		}
	}