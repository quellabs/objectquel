<?php
	
	namespace Quellabs\ObjectQuel\Sculpt;
	
	use Cake\Database\Connection;
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
				]);
			}
		}
		
		/**
		 * Returns the default configuration
		 * @return array<string, string|int>
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
				'metadata_cache_path' => ''
			];
		}

		/**
		 * Creates a configuration object out of the user provided data
		 * @return Configuration
		 */
		public function getConfiguration(): Configuration {
			$config = $this->getConfig();
			$defaults = $this->getDefaults();
			$configuration = new Configuration();
			
			$configuration->setEntityPath($config['entity_path'] ?? $defaults['entity_path'] ?? '');
			$configuration->setEntityNameSpace($config['entity_namespace'] ?? $defaults['entity_namespace'] ?? '');
			$configuration->setMigrationsPath($config['migrations_path'] ?? $defaults['migrations_path'] ?? '');

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
		 * @return array
		 */
		public function createPhinxConfig(): array {
			$defaults = $this->getDefaults();
			$configuration = $this->getConfig();
			
			return [
				'paths'        => [
					'migrations' => $configuration['migrations_path'] ?? $defaults['migrations_path'] ?? '',
				],
				'environments' => [
					'default_migration_table' => $configuration['migration_table'] ?? $defaults['migration_table'] ?? 'phinxlog',
					'default_environment'     => 'development',
					'development'             => [
						'adapter'   => $configuration['driver'] ?? $defaults['driver'] ?? 'mysql',
						'host'      => $configuration['host'] ?? $defaults['host'] ?? '',
						'name'      => $configuration['database'] ?? $defaults['database'] ?? '',
						'user'      => $configuration['username'] ?? $defaults['username'] ?? '',
						'pass'      => $configuration['password'] ?? $defaults['password'] ?? '',
						'port'      => $configuration['port'] ?? $defaults['port'] ?? 3306,
						'charset'   => $configuration['encoding'] ?? $defaults['encoding'] ?? 'utf8mb4',
						'collation' => $configuration['collation'] ?? $defaults['collation'] ?? 'utf8mb4_unicode_ci',
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
			
			// Load user configuration from config file
			$configData = $this->getConfig();
			
			// Resolve the driver
			$driver = $this->resolveDriver($configData['driver'] ?? $defaults['driver']);
			
			// Build final configuration with defaults as fallback
			return [
				'driver'        => $driver,
				'host'          => $configData['host'] ?? $defaults['host'],
				'username'      => $configData['username'] ?? $defaults['username'],
				'password'      => $configData['password'] ?? $defaults['password'],
				'database'      => $configData['database'] ?? $defaults['database'],
				'port'          => $configData['port'] ?? $defaults['port'],
				'encoding'      => $configData['encoding'] ?? $defaults['encoding'],
				'timezone'      => $configData['timezone'] ?? $defaults['timezone'],
				'flags'         => $configData['flags'] ?? $defaults['flags'],
				'cacheMetadata' => $configData['cacheMetadata'] ?? $defaults['cacheMetadata'],
				'log'           => $configData['log'] ?? $defaults['log'],
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