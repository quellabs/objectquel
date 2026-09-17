<?php

	namespace Quellabs\ObjectQuel\Migration;

	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Base class for hand-written and generated migrations. A migration is a
	 * plain PHP class with `up()`/`down()`, each a sequence of ObjectQuel DDL
	 * (and, for hand-written migrations, DML) statements run through
	 * `query()`.
	 *
	 * Deliberately has no `execute(string $rawSql)` escape hatch — a gap in
	 * Quel DDL should be closed in the DDL layer, not bypassed with raw SQL.
	 *
	 * File/class naming: `<YmdHis>_<ClassName>.php`, version = the timestamp
	 * — the Phinx-style convention, kept for sortable, diffable filenames.
	 */
	abstract class AbstractMigration {

		public function __construct(private readonly EntityManager $entityManager) {
		}

		/**
		 * Applies this migration.
		 * @return void
		 */
		abstract public function up(): void;

		/**
		 * Reverts this migration.
		 * @return void
		 */
		abstract public function down(): void;

		/**
		 * Runs a single ObjectQuel statement.
		 * @param string $quel
		 * @return void
		 */
		protected function query(string $quel): void {
			$this->entityManager->executeQuery($quel);
		}
	}
