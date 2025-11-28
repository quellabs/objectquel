<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys\Generators;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyGeneratorInterface;
	use RuntimeException;
	
	/**
	 * Generates RFC 4122 compliant UUID v4 primary keys
	 */
	class UuidGenerator implements PrimaryKeyGeneratorInterface {
		
		/**
		 * Generate a UUID v4 primary key
		 * @param EntityManager $em The EntityManager instance (unused)
		 * @param object $entity The entity object (unused)
		 * @return string A RFC 4122 compliant UUID v4 (e.g., "550e8400-e29b-41d4-a716-446655440000")
		 * @throws RuntimeException If cryptographically secure random bytes cannot be generated
		 */
		public function generate(EntityManager $em, object $entity): string {
			try {
				$data = random_bytes(16);
			} catch (\Exception $e) {
				throw new RuntimeException(
					'Failed to generate UUID: cryptographically secure random source unavailable',
					0,
					$e
				);
			}
			
			// Set version to 4 (random)
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
			
			// Set variant to RFC 4122
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
			
			// Format as 8-4-4-4-12 hex string
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
	}