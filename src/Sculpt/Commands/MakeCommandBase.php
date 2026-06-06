<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	/**
	 * Abstract base for make:* commands that operate on entity classes.
	 *
	 * Provides shared infrastructure:
	 *  - ORM configuration access
	 *  - Lazy-loaded EntityStore
	 *  - Validated entity name collection via collectIdentifier()
	 *  - Entity existence checks via validateEntityExists()
	 *  - PHP identifier validation via isValidPhpIdentifier()
	 */
	abstract class MakeCommandBase extends CommandBase {
		
		/** @var Configuration ORM configuration instance */
		protected Configuration $configuration;
		
		/** @var EntityStore|null Lazy-loaded entity store */
		private ?EntityStore $entityStore = null;
		
		/**
		 * @param ConsoleInput    $input    Console input handler
		 * @param ConsoleOutput   $output   Console output handler
		 * @param ServiceProvider $provider Service provider containing ORM configuration
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Prompts for an identifier, re-prompting until a valid PHP identifier is entered.
		 * Never returns an empty string or a PHP reserved keyword.
		 * @param string $prompt Text shown to the user
		 * @return string Validated entity name
		 */
		protected function collectIdentifier(string $prompt): string {
			while (true) {
				// Ask for entity name
				$name = $this->input->ask($prompt);
				
				// Reject empty response
				if ($name === null || trim($name) === '') {
					$this->output->warning("Identifier cannot be empty.");
					continue;
				}
				
				// Reject invalid names
				if (!$this->isValidPhpIdentifier($name)) {
					$this->output->warning("Invalid identifier. Use letters, numbers and underscores only.");
					continue;
				}
				
				// Return the name
				return $name;
			}
		}
		
		/**
		 * Probe the entity store for a matching entity class name.
		 * Tries the bare name first, then with the "Entity" suffix.
		 * Returns the registered class name, or null if neither exists.
		 */
		protected function resolveEntityClassName(string $rawInput): ?string {
			$store = $this->getEntityStore();
			
			if ($store->exists($rawInput)) {
				return $rawInput;
			}
			
			if ($store->exists($rawInput . 'Entity')) {
				return $rawInput . 'Entity';
			}
			
			return null;
		}
		
		/**
		 * Returns true when $name is a valid PHP identifier and not a reserved keyword.
		 * Covers both entity class names and property names: must start with a letter or
		 * underscore, followed by letters, digits, and underscores only.
		 * @param string $name Candidate identifier
		 * @return bool
		 */
		protected function isValidPhpIdentifier(string $name): bool {
			// Must start with letter or underscore, followed by letters/digits/underscores only
			if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
				return false;
			}
			
			// Reject PHP reserved keywords that are illegal as class or property names
			$reserved = [
				'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
				'class', 'clone', 'const', 'continue', 'declare', 'default', 'die',
				'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
				'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
				'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function',
				'global', 'goto', 'if', 'implements', 'include', 'include_once',
				'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match',
				'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
				'readonly', 'require', 'require_once', 'return', 'static', 'switch',
				'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
			];
			
			return !in_array(strtolower($name), $reserved, true);
		}
		
		/**
		 * Lazy-load EntityStore instance.
		 * @return EntityStore
		 */
		protected function getEntityStore(): EntityStore {
			return $this->entityStore ??= new EntityStore($this->configuration);
		}
		
		/**
		 * Ensure directory exists for file creation.
		 * @param string $directory Directory path to create
		 */
		protected function ensureDirectoryExists(string $directory): void {
			if (is_dir($directory)) {
				return;
			}
			
			if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
				throw new \RuntimeException("Failed to create directory: {$directory}");
			}
		}
		
		/**
		 * Derives a clean base name from a resolved entity class name.
		 * Strips the 'Entity' suffix if present and capitalizes the first letter.
		 * Used to produce consistent file and class names regardless of how
		 * the entity was registered in the store.
		 * @param string $entityClassName Resolved entity class name (e.g. "UserEntity" or "User")
		 * @return string Base name (e.g. "User")
		 */
		protected function deriveBaseName(string $entityClassName): string {
			if (str_ends_with($entityClassName, 'Entity')) {
				$entityClassName = substr($entityClassName, 0, -strlen('Entity'));
			}
			
			return ucfirst($entityClassName);
		}
	}