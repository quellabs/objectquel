<?php
	
	require('../vendor/autoload.php');
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	
	$config = new Configuration();
	$config->setEntityPaths([__DIR__ . '/../src/Entity']);
	$config->setProxyDir(__DIR__ . '/../src/Proxies');
	$config->setUseMetadataCache(true);
	$config->setMetadataCachePath(__DIR__ . '/../src/AnnotationCache');
	
	$config->setDatabaseParams(
		'mysql',                         // Driver
		$_ENV['DB_HOST'] ?? 'localhost', // Host
		$_ENV['DB_NAME'] ?? 'motorsportparts',// Database name
		$_ENV['DB_USER'] ?? 'root',   // Username
		$_ENV['DB_PASS'] ?? 'root',   // Password
		$_ENV['DB_PORT'] ?? 3306,        // Port
		$_ENV['DB_CHARSET'] ?? 'utf8mb4' // Character set
	);
	
	$entityManager = new EntityManager($config);
	
	/**
	$result = $entityManager->executeQuery("
		range of main is HamsterEntity
		retrieve (main) where main.woopie = /^hallo/
	");
	 */
	
	/*
	$hamster = new \Quellabs\ObjectQuel\Entity\ProductsEntity();
	$hamster->setGuid('xyz');
	$hamster->setProductsQuantity(0);
	$entityManager->persist($hamster);
	$entityManager->flush();
	*/
	

	$entity = $entityManager->find(\Quellabs\ObjectQuel\Entity\ProductsEntity::class, 1492);
	$entity->setWoocommerceId("baviaan");
	$entity->setGuid("kip");
	$entityManager->persist($entity);
	$entityManager->flush($entity);
	
	foreach($entity->productsDescriptions as $description) {
		echo $description->getLanguageId() . " - " . $description->getProductsDescription() . "\n\n";
	}
	