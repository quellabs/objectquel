<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	/**
	 * Conservative no-op platform used when no database connection is available.
	 *
	 * All capability flags return false, causing ObjectQuel to emit the most
	 * broadly-compatible SQL — plain REGEXP instead of REGEXP_LIKE(), etc.
	 * This is the default injected by QuelToSQL when the caller does not supply
	 * a platform instance.
	 */
	class NullPlatformCapabilities implements PlatformCapabilitiesInterface {
		
		/**
		 * @inheritDoc
		 */
		public function supportsNativeEnums(): bool {
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function supportsRegexpLike(): bool {
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function supportsWindowFunctions(): bool {
			return false;
		}
		
		/**
		 * @inheritDoc
		 */
		public function supportsIndexHiding(): bool {
			return false;
		}
		
		/**
		 * @inheritDoc
		 *
		 * Falls back to the most broadly-compatible fulltext style.
		 * MySQL/MariaDB/SQL Server syntax is the most widely recognised default
		 * when no database connection is available to detect the actual engine.
		 */
		public function getFulltextIndexStyle(): FulltextIndexStyle {
			return FulltextIndexStyle::Fulltext;
		}
		
		/**
		 * @inheritDoc
		 *
		 * Falls back to 'json', the most broadly-compatible type, when no database
		 * connection is available to detect the actual engine.
		 */
		public function getNativeJsonType(): string {
			return 'json';
		}
		
		/**
		 * Returns the JSON path extraction style used by the connected engine.
		 * @return JsonExtractionStyle
		 */
		public function getJsonExtractionStyle(): JsonExtractionStyle {
			return JsonExtractionStyle::JsonUnquote ;
		}
		
		/**
		 * @inheritDoc
		 *
		 * Returns the baseline cast types supported by MySQL/MariaDB, which are the
		 * most conservative valid set. Callers that need engine-specific types should
		 * inject a real PlatformCapabilities instance instead of relying on this null
		 * implementation.
		 */
		public function getSupportedCastTypes(): array {
			return [
				'int'     => 'SIGNED',
				'float'   => 'DOUBLE',
				'string'  => 'CHAR',
				'decimal' => 'DECIMAL',
			];
		}

		/**
		 * @inheritDoc
		 *
		 * Defaults to MySQL/MariaDB syntax, the most widely deployed engine in the
		 * Canvas/ObjectQuel target stack.
		 */
		public function getUnixTimestampFunction(): string {
			return 'UNIX_TIMESTAMP(%s)';
		}

		/**
		 * @inheritDoc
		 *
		 * Defaults to MySQL/MariaDB syntax.
		 */
		public function getCurrentUnixTimestamp(): string {
			return 'UNIX_TIMESTAMP()';
		}

		/**
		 * @inheritDoc
		 *
		 * Defaults to MySQL/MariaDB syntax, which PostgreSQL also accepts as-is.
		 */
		public function getCurrentDatetimeFunction(): string {
			return 'NOW()';
		}
		
		/**
		 * @inheritDoc
		 *
		 * Defaults to MySQL/MariaDB syntax, the most broadly recognised default
		 * when no database connection is available to detect the actual engine.
		 */
		public function getRegexpFallbackOperators(): array {
			return ['match' => 'REGEXP', 'notMatch' => 'NOT REGEXP'];
		}
	}