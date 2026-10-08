<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	/**
	 * Physical names built from a binding's (table, alias) identity — see "Binding
	 * identity and removal" in objectquel-equel-triggers-design.md. The alias, given with
	 * `as <alias>` or generated when omitted (see randomAlias()), is the only thing that needs
	 * to be unique per table; the binding's event and called routine are not part of the
	 * name, since the live trigger definition already carries the event, and the routine name
	 * is recovered from the trigger body when needed (see RoutineDependencyInspector).
	 */
	class EventBindingNaming {

		/** Conservative across all three engines: MySQL/MariaDB allow 64, PostgreSQL 63, SQL Server 128 */
		private const int MAX_LENGTH = 60;

		private const int HASH_LENGTH = 8;

		/** Hex characters of entropy in a generated alias; 16 million possibilities is ample per table */
		private const int RANDOM_ALIAS_BYTES = 4;

		/**
		 * Builds the physical trigger name for one binding.
		 * @param string $table Physical table the binding is on
		 * @param string $alias The binding's alias, given or generated
		 * @return string Name, truncated with a stable hash suffix if too long
		 */
		public static function triggerName(string $table, string $alias): string {
			return self::truncate(self::triggerNamePrefix($table) . $alias);
		}

		/**
		 * The untruncated prefix every trigger name starts with, before its alias. Used to
		 * recover a trigger's alias from its own name (see
		 * RoutineDependencyInspector::listBindings()) — only reliable when the full
		 * `eq_<table>_<alias>` identity didn't need truncate()'s hash shortening.
		 * @param string $table Physical table the binding is on
		 * @return string
		 */
		public static function triggerNamePrefix(string $table): string {
			return "eq_{$table}_";
		}

		/**
		 * Generates an opaque alias for a binding created without `as <alias>`. Random,
		 * not derived from the binding's identity, so unbinding and rebinding the same
		 * (table, event, routine) never reuses a stale name. Shown back by `quel:list-triggers`
		 * for later reference in `destroy trigger`.
		 * @return string Lowercase hex string
		 */
		public static function randomAlias(): string {
			return bin2hex(random_bytes(self::RANDOM_ALIAS_BYTES));
		}

		/**
		 * Builds the name of the generated PostgreSQL helper function a trigger calls
		 * (PostgreSQL triggers can't call a routine directly — see PostgresEventBindingLowering).
		 * @param string $triggerName This binding's trigger name
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
