<?php
	
	return [
		'driver'           => 'mysql',                  // Database driver (mysql, postgresql, sqlite, etc.)
		'host'             => 'localhost',              // Database server hostname or IP address
		'database'         => '',                       // Name of the database to connect to
		'username'         => '',                       // Database username for authentication
		'password'         => '',                       // Database password for authentication
		'port'             => 3306,                     // Database server port (3306 is MySQL default)
		'charset'          => 'utf8mb4',                // Character set for database connection
		'collation'        => 'utf8mb4_unicode_ci',     // Collation for text comparison and sorting
		
		// Entity namespace
		'entity_namespace' => 'App\\Entities',
		
		// Path to the entities folder
		'entity_path'      => dirname(__FILE__) . '/../src/Entities/',
		
		// Path to the proxy folder
		'proxy_path'       => dirname(__FILE__) . '/../storage/objectquel/proxies/',
		
		// Path to the migrations folder
		'migrations_path'  => dirname(__FILE__) . '/../migrations',
	];