<?php
	
	namespace Quellabs\ObjectQuel\Database;
	
	/**
	 * Conservative no-op platform used when no database connection is available.
	 *
	 * All capability flags return false, causing ObjectQuel to emit the most
	 * broadly-compatible SQL — plain REGEXP instead of REGEXP_LIKE(), etc.
	 * This is the default injected by QuelToSQL when the caller does not supply
	 * a platform instance.
	 */
	class NullDatabasePlatform implements DatabasePlatformInterface {
		
		/**
		 * @inheritDoc
		 */
		public function supportsRegexpLike(): bool {
			return false;
		}
	}