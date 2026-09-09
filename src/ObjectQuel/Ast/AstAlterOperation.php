<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * Marker interface for one sub-operation inside an `alter Name (...)`
	 * statement's parenthesized, comma-separated operation list — see
	 * AstAlterTable and objectquel-alter-table-design.md.
	 *
	 * Each ObjectQuel sub-operation keyword (`add`, `drop`, `rename`,
	 * `retype`, `primary key (...)`, `drop primary key`, `add index`,
	 * `drop index`) has its own node implementing this interface, matching
	 * QUEL's preference for explicit single-purpose verbs over one
	 * do-everything clause.
	 */
	interface AstAlterOperation {
	}
