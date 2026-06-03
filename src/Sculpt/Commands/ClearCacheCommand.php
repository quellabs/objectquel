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
	
	class ClearCacheCommand extends CommandBase {
		
		/** @var Configuration ORM configuration passed in via the service provider */
		private Configuration $configuration;
		
		/** @var ServiceProvider */
		protected ProviderInterface $provider;
		
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