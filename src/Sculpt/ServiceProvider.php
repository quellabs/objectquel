<?php
	
	namespace Quellabs\ObjectQuel\Sculpt;
	
	use Cake\Database\Connection;
	use Quellabs\Support\ComposerUtils;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\Sculpt\Application;
	use Quellabs\ObjectQuel\Configuration;
	
	/**
	 * ObjectQuel service provider for the Sculpt framework
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		/**
		 * @var DatabaseAdapter|null Database adapter
		 */
		private ?DatabaseAdapter $adapter = null;
		
		/**
		 * Register all ObjectQuel commands with the Sculpt application
		 * @param Application $application
		 */
		public function register(Application $application): void {
			// Register the commands into the Sculpt application
			if (!empty($this->getConfig())) {
				$this->registerCommands($application, [
					\Quellabs\ObjectQuel\Sculpt\Commands\InitCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityFromTableCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\MakeRepositoryCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\MakeMigrationsCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelCreatePhinxConfigCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\PacGenerateEntityCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelIndexHideCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelIndexShowCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\ClearCacheCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\ListEntitiesCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\AnalyzeIndexesCommand::class,
				]);
			}
		}
		
		/**
		 * Returns the default configuration
		 * @return array{
		 *      driver: string,
		 *      host: string,
		 *      database: string,
		 *      username: string,
		 *      password: string,
		 *      port: int,
		 *      encoding: string,
		 *      collation: string,
		 *      migrations_path: string,
		 *      entity_namespace: string,
		 *      entity_path: string,
		 *      proxy_namespace: string,
		 *      proxy_path: string,
		 *      metadata_cache_path: string
		 * }
		 */
		public static function getDefaults(): array {
			return [
				'driver'              => 'mysql',
				'host'                => '',
				'database'            => '',
				'username'            => '',
				'password'            => '',
				'port'                => 3306,
				'encoding'            => 'utf8mb4',
				'collation'           => 'utf8mb4_unicode_ci',
				'migrations_path'     => '',
				'entity_namespace'    => '',
				'entity_path'         => '',
				'proxy_namespace'     => 'Quellabs\\ObjectQuel\\Proxy\\Runtime',
				'proxy_path'          => '',
				'metadata_cache_path' => ComposerUtils::getProjectRoot() . "/storage/annotations"
			];
		}
		
		/**
		 * Creates a configuration object out of the user provided data
		 * @return Configuration
		 */
		public function getConfiguration(): Configuration {
			$defaults = $this->getDefaults();
			
			$configuration = new Configuration();
			$configuration->setEntityPath($this->getConfigValueAsString('entity_path', $defaults['entity_path']));
			$configuration->setEntityNameSpace($this->getConfigValueAsString('entity_namespace', $defaults['entity_namespace']));
			$configuration->setMigrationsPath($this->getConfigValueAsString('migrations_path', $defaults['migrations_path']));
			$configuration->setMetadataCachePath($this->getConfigValueAsString('metadata_cache_path', $defaults['metadata_cache_path']));
			$configuration->setUseMetadataCache(!empty($this->getConfigValueAsString('metadata_cache_path', $defaults['metadata_cache_path'])));
			return $configuration;
		}
		
		/**
		 * Returns the database connector using lazy initialization pattern
		 * @return DatabaseAdapter The database adapter instance
		 */
		public function getDatabaseAdapter(): DatabaseAdapter {
			// Return existing instance if already created (singleton behavior)
			if ($this->adapter !== null) {
				return $this->adapter;
			}
			
			// Build the connection configuration from config file and defaults
			$config = $this->buildConnectionConfig();
			
			// Create and cache the connection instance
			$connection = new Connection($config);
			
			// Create a new database adapter with connection
			$this->adapter = new DatabaseAdapter($connection);
			
			// Return the singleton instance
			return $this->adapter;
		}
		
		/**
		 * Returns a Phinx configuration array
		 * @return array{
		 *     paths: array{
		 *         migrations: string
		 *     },
		 *     environments: array{
		 *         default_migration_table: string,
		 *         default_environment: string,
		 *         development: array{
		 *             adapter: string,
		 *             host: string,
		 *             name: string,
		 *             user: string,
		 *             pass: string,
		 *             port: int,
		 *             charset: string,
		 *             collation: string
		 *         }
		 *     }
		 * }
		 */
		public function createPhinxConfig(): array {
			// Fetch default values
			$defaults = $this->getDefaults();
			
			// Make a phinx config configuration array
			return [
				'paths'        => [
					'migrations' => $this->getConfigValueAsString('migrations_path', $defaults['migrations_path']),
				],
				'environments' => [
					'default_migration_table' => $this->getConfigValueAsString('migration_table', 'phinxlog'),
					'default_environment'     => 'development',
					'development'             => [
						'adapter'   => $this->getConfigValueAsString('driver', $defaults['driver']),
						'host'      => $this->getConfigValueAsString('host', $defaults['host']),
						'name'      => $this->getConfigValueAsString('database', $defaults['database']),
						'user'      => $this->getConfigValueAsString('username', $defaults['username']),
						'pass'      => $this->getConfigValueAsString('password', $defaults['password']),
						'port'      => $this->getConfigValueAsInt('port', $defaults['port']),
						'charset'   => $this->getConfigValueAsString('encoding', $defaults['encoding']),
						'collation' => $this->getConfigValueAsString('collation', $defaults['collation']),
					],
				],
			];
		}
		
		/**
		 * Builds the connection configuration array from config data and defaults
		 * @return array<string, mixed> Complete connection configuration array
		 */
		private function buildConnectionConfig(): array {
			// Get default configuration values
			$defaults = self::getDefaults();
			
			// Resolve the driver
			$driver = $this->resolveDriver($this->getConfigValueAsString('driver', $defaults['driver']));
			
			// Build final configuration with defaults as fallback
			return [
				'driver'   => $driver,
				'host'     => $this->getConfigValueAsString('host', $defaults['host']),
				'username' => $this->getConfigValueAsString('username', $defaults['username']),
				'password' => $this->getConfigValueAsString('password', $defaults['password']),
				'database' => $this->getConfigValueAsString('database', $defaults['database']),
				'port'     => $this->getConfigValueAsInt('port', $defaults['port']),
				'encoding' => $this->getConfigValueAsString('encoding', $defaults['encoding']),
			];
		}
		
		/**
		 * Converts short driver names (mysql, postgres, etc.) to their corresponding
		 * CakePHP driver class names. If a fully qualified class name is already
		 * provided, it is returned as-is.
		 * @param string $driver The driver name or class to resolve
		 * @return string The fully qualified driver class name
		 */
		private function resolveDriver(string $driver): string {
			// Map of short driver names to fully qualified class names
			$driverMap = [
				'mysql'     => \Cake\Database\Driver\Mysql::class,
				'postgres'  => \Cake\Database\Driver\Postgres::class,
				'sqlite'    => \Cake\Database\Driver\Sqlite::class,
				'sqlserver' => \Cake\Database\Driver\Sqlserver::class,
			];
			
			// Return the mapped class name, or the original value if not in map
			// This allows users to specify either short names or full class names
			return $driverMap[$driver] ?? $driver;
		}
	}