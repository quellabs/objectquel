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
    php sculpt make:repository [--force]

OPTIONS:
    --force       Overwrite the repository file if it already exists

EXAMPLES:
    php sculpt make:repository
        Prompts for an entity name and creates the corresponding repository

    php sculpt make:repository --force
        Same as above, but overwrites any existing repository file

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
				// Ask for the entity name
				$entityName = $this->collectEntityName('Enter the entity name (e.g. User, UserEntity, Product)');
				
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
				
				// Construct the full file path where the repository will be created
				$repositoryPath = $this->buildRepositoryPath($repositoryBaseName);

				// Check if we should proceed with creation (handles existing files and force flag)
				if (!$this->shouldCreateRepository($repositoryPath, $config->hasFlag('force'))) {
					return 0;
				}

				// Generate and write the repository file to disk
				$this->createRepositoryFile($entityClassName, $repositoryBaseName, $repositoryPath);

				// Display success message with the created repository name and location
				$this->output->writeLn("Repository '{$repositoryBaseName}" . self::REPOSITORY_SUFFIX . "' created successfully at: {$repositoryPath}");

				// Return success exit code
				return 0;

			} catch (RuntimeException $e) {
				// Handle expected runtime errors (e.g., file system issues, validation failures)
				$this->output->writeLn("Error: {$e->getMessage()}");
				return 1;
			} catch (\Throwable $e) {
				// Catch any other unexpected exceptions to prevent crashes
				$this->output->writeLn("Unexpected error: {$e->getMessage()}");
				return 1;
			}
		}
		
		/**
		 * Build the complete file path for the repository.
		 * @param string $repositoryBaseName Base name for repository (without suffix)
		 * @return string Complete file path
		 */
		private function buildRepositoryPath(string $repositoryBaseName): string {
			$fileName = $repositoryBaseName . self::REPOSITORY_SUFFIX . '.php';
			return ComposerUtils::getProjectRoot() . self::REPOSITORY_DIRECTORY . $fileName;
		}

		/**
		 * Check if repository should be created (handles existing files).
		 * @param string $repositoryPath Path to repository file
		 * @param bool   $force          Whether to force overwrite
		 * @return bool True if should create, false to skip
		 */
		private function shouldCreateRepository(string $repositoryPath, bool $force): bool {
			if (!file_exists($repositoryPath)) {
				return true;
			}

			if ($force) {
				$this->output->writeLn("Overwriting existing repository file: {$repositoryPath}");
				return true;
			}

			$basename = basename($repositoryPath);
			$this->output->writeLn("Repository '{$basename}' already exists at: {$repositoryPath}");
			$this->output->writeLn("Use --force flag to overwrite the existing file.");

			return false;
		}

		/**
		 * Create the repository file with generated content.
		 * @param string $originalEntityName Original entity name for template
		 * @param string $repositoryBaseName  Base name for repository class
		 * @param string $repositoryPath      File path to create
		 */
		private function createRepositoryFile(string $originalEntityName, string $repositoryBaseName, string $repositoryPath): void {
			$this->ensureDirectoryExists(dirname($repositoryPath));

			$repositoryContent = $this->generateRepositoryContent($originalEntityName, $repositoryBaseName);

			if (file_put_contents($repositoryPath, $repositoryContent) === false) {
				throw new RuntimeException("Failed to create repository file: {$repositoryPath}");
			}
		}

		/**
		 * Generate repository class content from template.
		 * @param string $originalEntityName Original entity name for import
		 * @param string $repositoryBaseName  Base name for repository class
		 * @return string Complete PHP class content
		 */
		private function generateRepositoryContent(string $originalEntityName, string $repositoryBaseName): string {
			$namespace = self::REPOSITORY_NAMESPACE;
			$repositoryClass = $repositoryBaseName . self::REPOSITORY_SUFFIX;

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