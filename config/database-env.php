<?php
	
	/**
	 * Database Environment Configuration
	 * Handles reading database configuration from environment variables, user settings, and defaults
	 * Priority: Environment variables > User settings > Defaults
	 */
	
	if (!function_exists('getDatabaseConnectionData')) {
		/**
		 * Get database connection data with proper precedence
		 * @return array Complete database configuration
		 */
		function getDatabaseConnectionData(): array {
			// First check for DSN (takes precedence over everything)
			if (!empty($_ENV["DSN"])) {
				return parseDsn($_ENV["DSN"]);
			}
			
			// Include database.php to get user settings merged with environment variables
			$databaseFile = __DIR__ . '/database.php';
			
			if (file_exists($databaseFile)) {
				// Return the credential array from database.php
				$databaseConfig = include $databaseFile;
				return is_array($databaseConfig) ? $databaseConfig : [];
			}
			
			// Fallback if database.php doesn't exist - use only environment variables
			return array_filter([
				'driver'   => $_ENV['DB_DRIVER'] ?? null,
				'host'     => $_ENV['DB_HOST'] ?? null,
				'database' => $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? null,
				'username' => $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? null,
				'password' => $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? null,
				'port'     => isset($_ENV['DB_PORT']) ? (int)$_ENV['DB_PORT'] : null,
				'charset'  => $_ENV['DB_CHARSET'] ?? null,
				'collation'=> $_ENV['DB_COLLATION'] ?? null,
			], fn($value) => $value !== null);
		}
	}
	
	if (!function_exists('parseDsn')) {
		/**
		 * Parse a DSN string into an array of database connection parameters
		 * @param string $dsn The DSN string to parse
		 * @return array Array of connection parameters
		 * @throws InvalidArgumentException If the DSN format is invalid
		 */
		function parseDsn(string $dsn): array {
			// Parse the DSN
			$parsed = parse_url($dsn);
			
			// Return nothing if the url can't be parsed
			if ($parsed === false) {
				return [];
			}
			
			// Build a result array directly from parsed components
			$result = array_filter([
				'driver'   => $parsed['scheme'] ?? null,
				'host'     => $parsed['host'] ?? null,
				'database' => isset($parsed['path']) ? ltrim($parsed['path'], '/') : null,
				'username' => $parsed['user'] ?? null,
				'password' => $parsed['pass'] ?? null,
				'port'     => $parsed['port'] ?? null,
			], fn($value) => $value !== null);
			
			// Handle query parameters efficiently
			if (isset($parsed['query'])) {
				parse_str($parsed['query'], $queryParams);
				
				// Extract encoding (charset takes precedence)
				$encoding = $queryParams['charset'] ?? $queryParams['encoding'] ?? null;
				
				if ($encoding) {
					$result['encoding'] = $encoding;
				}
				
				// Extract remaining flags
				$flags = array_diff_key($queryParams, array_flip(['charset', 'encoding']));
				
				if ($flags) {
					$result['flags'] = $flags;
				}
			}
			
			return $result;
		}
	}
	
	// Return the database connection data
	return getDatabaseConnectionData();