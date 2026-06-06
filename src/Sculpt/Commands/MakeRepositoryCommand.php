<?php

	namespace Quellabs\ObjectQuel\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\ComposerUtils;
	use RuntimeException;

	/**
	 * This command creates repository classes that extend the base Repository class
	 * and are associated with existing entity classes. It includes validation,
	 * interactive prompts, and proper error handling.
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
Usage: make:repository [options]

Creates a new repository class in the App\Repository namespace that extends
the base Repository class and is associated with an existing entity.

Options:
  --force        Overwrite the repository file if it already exists

Examples:
  make:repository
  make:repository --force

You will be prompted to enter the entity name. The command will verify that
the entity class exists before creating the repository.
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

				// Store the original entity name for later use in file generation
				$originalEntityName = trim($entityName);

				// Clean and format the entity name for use in repository class naming
				$repositoryBaseName = $this->sanitizeEntityName($originalEntityName);

				// Check if the specified entity actually exists in the system
				if (!$this->validateEntityExists($originalEntityName)) {
					return 1;
				}

				// Construct the full file path where the repository will be created
				$repositoryPath = $this->buildRepositoryPath($repositoryBaseName);

				// Check if we should proceed with creation (handles existing files and force flag)
				if (!$this->shouldCreateRepository($repositoryPath, $config->hasFlag('force'))) {
					return 0;
				}

				// Generate and write the repository file to disk
				$this->createRepositoryFile($originalEntityName, $repositoryBaseName, $repositoryPath);

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
		 * Clean and format the entity name for repository naming.
		 * Removes both 'Repository' and 'Entity' suffixes.
		 * @param string $entityName Raw entity name input
		 * @return string Cleaned entity name for repository base name
		 */
		private function sanitizeEntityName(string $entityName): string {
			// Remove 'Repository' suffix if provided
			if (str_ends_with($entityName, self::REPOSITORY_SUFFIX)) {
				$entityName = substr($entityName, 0, -strlen(self::REPOSITORY_SUFFIX));
			}

			// Remove 'Entity' suffix if provided
			if (str_ends_with($entityName, 'Entity')) {
				$entityName = substr($entityName, 0, -strlen('Entity'));
			}

			// Capitalize first letter
			return ucfirst($entityName);
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

		/**
		 * Ensure directory exists for file creation.
		 * @param string $directory Directory path to create
		 */
		private function ensureDirectoryExists(string $directory): void {
			if (is_dir($directory)) {
				return;
			}

			if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
				throw new RuntimeException("Failed to create directory: {$directory}");
			}
		}
	}