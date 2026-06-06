<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	use RuntimeException;

	/**
	 * MakeRepositoryCommand - Create a new repository class for an existing entity
	 *
	 * Generates a typed repository class in App\Repositories that extends the base
	 * Repository class and is bound to an existing entity. The target entity must
	 * exist before running this command.
	 */
	class MakeRepositoryCommand extends MakeCommandBase {
		
		/** Namespace for generated repository classes */
		private const string REPOSITORY_NAMESPACE = 'App\\Repositories';
		
		/** Directory path for repository files */
		private const string REPOSITORY_DIRECTORY = '/src/Repositories/';
		
		/** Suffix for repository class names */
		private const string REPOSITORY_SUFFIX = 'Repository';
		
		/**
		 * Get the command name for CLI usage.
		 * @return string Command signature
		 */
		public function getSignature(): string {
			return 'make:repository';
		}
		
		/**
		 * Get a brief description of the command.
		 * @return string Brief description
		 */
		public function getDescription(): string {
			return 'Create a new repository class for an existing entity';
		}
		
		/**
		 * Get detailed help information for the command.
		 * @return string Help text with usage examples
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Creates a new repository class in the App\Repositories namespace that extends
    the base Repository class and is bound to an existing entity. The entity must
    exist before the repository can be generated.

USAGE:
    php sculpt make:repository [entity] [--force]

ARGUMENTS:
    entity     Optional entity class name. If omitted, you will be prompted.

OPTIONS:
    --force    Overwrite the repository file if it already exists

EXAMPLES:
    php sculpt make:repository User

NOTES:
    - The entity class must already exist in the configured entity path
    - "Entity" and "Repository" suffixes in the input are stripped automatically
    - Generated file is placed in src/Repositories/
HELP;
		}
		
		/**
		 * Execute the repository creation process.
		 * @param ConfigurationManager $config Command configuration and arguments
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			try {
				// Ask for entity name
				$entityName = $config->getPositional(0);
				
				if ($entityName === null) {
					$entityName = $this->collectIdentifier("Entity name");
				} elseif (!$this->isValidPhpIdentifier($entityName)) {
					$this->output->error("Invalid entity name '{$entityName}'.");
					return 1;
				}
				
				// Resolve the actual entity class name as registered in the store.
				// The user may have typed "User", "UserEntity", or "UserRepository" —
				// derive the base name first, then probe the store for known conventions.
				$entityClassName = $this->resolveEntityClassName($entityName);
				
				if ($entityClassName === null) {
					$this->output->writeLn("Entity '{$entityName}' does not exist. Please ensure the entity class exists before creating a repository.");
					$this->output->writeLn("Available entities can be listed with: php sculpt list:entities");
					return 1;
				}
				
				// Clean and format the entity name for use in repository class naming
				$repositoryBaseName = $this->deriveBaseName($entityClassName);
				
				// Determine the class name
				$repositoryClass = $repositoryBaseName . self::REPOSITORY_SUFFIX;
				
				// Construct the full file path where the repository will be created
				$repositoryPath = $this->resolveRepositoryPath($repositoryClass);
				
				// Check if we should proceed with creation (handles existing files and force flag)
				if (!$this->shouldCreateRepository($repositoryPath, $config->hasFlag('force'))) {
					$basename = basename($repositoryPath);
					
					$this->output->writeLn("Repository '{$basename}' already exists at: {$repositoryPath}");
					$this->output->writeLn("Use --force flag to overwrite the existing file.");
					
					return 0;
				}
				
				// Generate content
				$repositoryContent = $this->generateRepositoryContent($entityClassName, $repositoryClass);
				
				// Make target directory if it does not already exist
				$this->ensureDirectoryExists(dirname($repositoryPath));
				
				// Write the data. If that failed, throw an error
				if (file_put_contents($repositoryPath, $repositoryContent) === false) {
					throw new RuntimeException("Failed to create repository file: {$repositoryPath}");
				}
				
				// Display success message with the created repository name and location
				$this->output->writeLn("Repository '{$repositoryBaseName}" . self::REPOSITORY_SUFFIX . "' created successfully at: {$repositoryPath}");
				
				// Return success exit code
				return 0;
				
			} catch (RuntimeException $e) {
				// Handle expected runtime errors (e.g., file system issues, validation failures)
				$this->output->error("Error: {$e->getMessage()}");
				return 1;
			} catch (\Throwable $e) {
				// Catch any other unexpected exceptions to prevent crashes
				$this->output->error("Unexpected error: {$e->getMessage()}");
				return 1;
			}
		}
		
		/**
		 * Resolves the complete file path for the repository.
		 * @param string $repositoryBaseName Base name for repository (without suffix)
		 * @return string Complete file path
		 */
		private function resolveRepositoryPath(string $repositoryBaseName): string {
			$fileName = $repositoryBaseName . '.php';
			return ComposerUtils::getProjectRoot() . self::REPOSITORY_DIRECTORY . $fileName;
		}
		
		/**
		 * Check if repository should be created (handles existing files).
		 * @param string $repositoryPath Path to repository file
		 * @param bool $force Whether to force overwrite
		 * @return bool True if should create, false to skip
		 */
		private function shouldCreateRepository(string $repositoryPath, bool $force): bool {
			// Always create when file exists
			if (!file_exists($repositoryPath)) {
				return true;
			}
			
			// Only overwrite when instructed to do so
			if ($force) {
				$this->output->writeLn("Overwriting existing repository file: {$repositoryPath}");
				return true;
			}
			
			return false;
		}
		
		/**
		 * Generate repository class content from template.
		 * @param string $originalEntityName Original entity name for import
		 * @param string $repositoryClass Class name
		 * @return string Complete PHP class content
		 */
		private function generateRepositoryContent(string $originalEntityName, string $repositoryClass): string {
			$namespace = self::REPOSITORY_NAMESPACE;
			
			return <<<PHP
<?php

    namespace {$namespace};
    
    use App\Entities\\{$originalEntityName};
    use Quellabs\ObjectQuel\Repository;
    use Quellabs\ObjectQuel\EntityManager;
    
    /**
     * Repository for {$originalEntityName} entities.
     */
    class {$repositoryClass} extends Repository {
        /**
         * Initialize the repository with the associated entity type.
         */
        public function __construct(EntityManager \$entityManager) {
            parent::__construct(\$entityManager, {$originalEntityName}::class);
        }
    }
PHP;
		}
	}
