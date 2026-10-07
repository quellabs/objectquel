<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;

	/**
	 * Deterministic physical names generated from an attachment's (table, event, routine)
	 * identity — see "Attachment identity and removal" in objectquel-equel-triggers-design.md.
	 * The generated name is an implementation detail, never EQUEL source syntax.
	 */
	class EventAttachmentNaming {

		/** Conservative across all three engines: MySQL/MariaDB allow 64, PostgreSQL 63, SQL Server 128 */
		private const int MAX_LENGTH = 60;

		private const int HASH_LENGTH = 8;

		/**
		 * Builds the physical trigger name for one attachment.
		 * @param string $table Physical table the attachment is on
		 * @param AttachmentEvent $event Physical write event
		 * @param string $routineName Called routine's name
		 * @return string Deterministic name, truncated with a stable hash suffix if too long
		 */
		public static function triggerName(string $table, AttachmentEvent $event, string $routineName): string {
			return self::truncate("eq_{$table}_{$event->value}_{$routineName}");
		}

		/**
		 * Builds the name of the generated PostgreSQL helper function a trigger calls
		 * (PostgreSQL triggers can't call a routine directly — see PostgresEventAttachmentLowering).
		 * @param string $triggerName This attachment's trigger name
		 * @return string
		 */
		public static function helperFunctionName(string $triggerName): string {
			return self::truncate("{$triggerName}_fn");
		}

		/**
		 * Truncates a generated identifier to a safe length, keeping it collision-resistant by
		 * hashing the full, untruncated name into the kept suffix rather than just cutting it off.
		 * @param string $identity Full, human-readable identity string
		 * @return string
		 */
		private static function truncate(string $identity): string {
			if (strlen($identity) <= self::MAX_LENGTH) {
				return $identity;
			}

			$hash = substr(hash('sha256', $identity), 0, self::HASH_LENGTH);
			$prefixLength = self::MAX_LENGTH - self::HASH_LENGTH - 1;

			return substr($identity, 0, $prefixLength) . '_' . $hash;
		}
	}
