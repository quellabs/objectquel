<?php

	namespace Quellabs\ObjectQuel\Migration;

	/**
	 * A single migration file MigrationLocator found and instantiated —
	 * its version (the `<YmdHis>` filename timestamp), its class name, and
	 * the ready-to-run AbstractMigration instance itself.
	 */
	final class LocatedMigration {

		public function __construct(
			public readonly int $version,
			public readonly string $name,
			public readonly AbstractMigration $migration,
		) {
		}
	}
