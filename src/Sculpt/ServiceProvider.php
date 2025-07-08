<?php
	
	namespace Quellabs\ObjectQuel\Sculpt;
	
	use Quellabs\Sculpt\Application;
	use Quellabs\ObjectQuel\Configuration;
	
	/**
	 * ObjectQuel service provider for the Sculpt framework
	 */
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
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
					\Quellabs\ObjectQuel\Sculpt\Commands\MakeMigrationsCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand::class,
					\Quellabs\ObjectQuel\Sculpt\Commands\QuelCreatePhinxConfigCommand::class,
				]);
			}
		}
		
		/**
		 * Returns the default configuration
		 * @return array[]
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
			
			$configuration->setConnectionParams([
				'driver'    => $config['driver'] ?? $defaults['driver'] ?? 'mysql',
				'host'      => $config['host'] ?? $defaults['host'] ?? 'localhost',
				'database'  => $config['database'] ?? $defaults['database'] ?? '',
				'username'  => $config['username'] ?? $defaults['username'] ?? '',
				'password'  => $config['password'] ?? $defaults['password'] ?? '',
				'port'      => $config['port'] ?? $defaults['port'] ?? 3306,
				'encoding'  => $config['encoding'] ?? $defaults['encoding'] ?? 'utf8mb4',
				'collation' => $config['collation'] ?? $defaults['collation'] ?? 'utf8mb4_unicode_ci',
			]);
			
			return $configuration;
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
	}