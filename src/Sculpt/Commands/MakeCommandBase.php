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
	 *  - Validated entity name collection via collectEntityName()
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
		 * Lazy-load EntityStore instance.
		 * @return EntityStore
		 */
		protected function getEntityStore(): EntityStore {
			return $this->entityStore ??= new EntityStore($this->configuration);
		}
		
		/**
		 * Prompts for an entity name, re-prompting until a valid PHP identifier is entered.
		 * Never returns an empty string or a PHP reserved keyword.
		 * @param string $prompt Text shown to the user
		 * @return string Validated entity name
		 */
		protected function collectEntityName(string $prompt): string {
			while (true) {
				// Ask for entity name
				$name = $this->input->ask($prompt);
				
				// Reject empty response
				if ($name === null || trim($name) === '') {
					$this->output->warning("Entity name cannot be empty.");
					continue;
				}
				
				// Reject invalid names
				if (!$this->isValidPhpIdentifier($name)) {
					$this->output->warning("Invalid entity name. Use letters, numbers and underscores only.");
					continue;
				}
				
				// Return the name
				return $name;
			}
		}
		
		/**
		 * Check whether the named entity class exists in the entity store.
		 * Writes a descriptive error to output and returns false when it does not.
		 * @param string $entityName Entity class name to validate
		 * @return bool True if the entity exists, false otherwise
		 */
		protected function validateEntityExists(string $entityName): bool {
			if (!$this->getEntityStore()->exists($entityName)) {
				$this->output->writeLn("Entity '{$entityName}' does not exist. Please ensure the entity class exists before creating a repository.");
				$this->output->writeLn("Available entities can be listed with: php sculpt list:entities");
				return false;
			}
			
			return true;
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
	}