<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Commands;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;
	
	/**
	 * ClearCacheCommand - Clear all ObjectQuel caches
	 *
	 * Removes cached entity annotation metadata so that the next request
	 * picks up any changes to entity definitions without requiring a
	 * manual cache invalidation.
	 */
	class ClearCacheCommand extends CommandBase {
		
		/** @var Configuration ORM configuration passed in via the service provider */
		private Configuration $configuration;
		
		/**
		 * Constructor
		 * @param ConsoleInput $input Console input handler
		 * @param ConsoleOutput $output Console output handler
		 * @param ServiceProvider $provider Service provider exposing configuration
		 */
		public function __construct(ConsoleInput $input, ConsoleOutput $output, ServiceProvider $provider) {
			parent::__construct($input, $output, $provider);
			$this->configuration = $provider->getConfiguration();
		}
		
		/**
		 * Returns the Sculpt command signature used to invoke this command.
		 * @return string
		 */
		public function getSignature(): string {
			return "quel:clear-cache";
		}
		
		/**
		 * Returns a short one-line description shown in the command list.
		 * @return string
		 */
		public function getDescription(): string {
			return "Clear all ObjectQuel caches";
		}
		
		/**
		 * Returns extended help text displayed when --help is passed.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Clears the entity annotation cache used by ObjectQuel to speed up metadata
    resolution. Run this after modifying entity annotations or column definitions
    to ensure the ORM picks up the latest changes.

USAGE:
    php sculpt quel:clear-cache

EXAMPLES:
    php sculpt quel:clear-cache
        Deletes all cached annotation files for entities annotated with @Table

NOTES:
    - Only clears entity annotation caches; does not affect query or result caches
    - Safe to run in production; the cache is rebuilt automatically on next access
HELP;
		}
		
		/**
		 * Clear entity annotation cache files
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 for success, 1 for failure)
		 */
		public function execute(ConfigurationManager $config): int {
			// Build the annotation reader configuration directly from the ObjectQuel
			// configuration — no database connection or EntityManager needed
			$annotationReaderConfig = new \Quellabs\AnnotationReader\Configuration();
			$annotationReaderConfig->setUseAnnotationCache($this->configuration->useMetadataCache());
			$annotationReaderConfig->setAnnotationCachePath($this->configuration->getMetadataCachePath());
			
			// Clear only entity annotation caches, identified by the Table annotation class
			$annotationReader = new AnnotationReader($annotationReaderConfig);
			$annotationReader->clearCacheByAnnotationClass(Table::class);
			
			$this->output->success("Entity cache cleared");
			return 0;
		}
	}