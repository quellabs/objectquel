<?php

	require_once __DIR__ . '/../../../vendor/autoload.php';

	// PSR-4 autoloading for this package's own tests. Not registered via
	// composer.json's autoload-dev to avoid requiring a `composer dump-autoload`
	// at the monorepo root just to run this package's suite in isolation.
	spl_autoload_register(function (string $class): void {
		$prefix = 'Quellabs\\ObjectQuel\\Tests\\';

		if (!str_starts_with($class, $prefix)) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

		if (is_file($path)) {
			require_once $path;
		}
	});
