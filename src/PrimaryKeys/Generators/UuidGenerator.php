<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys\Generators;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyGeneratorInterface;
	
	/**
	 * UuidGenerator class for generating UUID (Universally Unique Identifier) primary keys
	 */
	class UuidGenerator implements PrimaryKeyGeneratorInterface {
		
		/**
		 * Generate a new UUID primary key for the given entity
		 * @param EntityManager $em The EntityManager instance (not used in this implementation)
		 * @param object $entity The entity object for which to generate a primary key (not used in this implementation)
		 * @return string The generated UUID
		 */
		public function generate(EntityManager $em, object $entity): string {
			// Method 1: Using com_create_guid() function (Windows only)
			if (function_exists('com_create_guid')) {
				return trim(com_create_guid(), "{}");
			}
			
			// Method 2: Using openssl_random_pseudo_bytes() function
			if (function_exists('openssl_random_pseudo_bytes')) {
				$data = openssl_random_pseudo_bytes(16);
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100 (UUID version 4)
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10 (UUID variant 1)
				
				// Format the UUID string
				return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
			}
			
			// Method 3: Fallback method using mt_rand() and uniqid()
			mt_srand((double)microtime() * 10000);
			$charId = strtolower(md5(uniqid(rand(), true)));
			$hyphen = chr(45); // Hyphen character
			
			// Format the UUID string manually
			return substr($charId, 0, 8) . $hyphen .
				substr($charId, 8, 4) . $hyphen .
				substr($charId, 12, 4) . $hyphen .
				substr($charId, 16, 4) . $hyphen .
				substr($charId, 20, 12);
		}
	}