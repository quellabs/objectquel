<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeTable;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;

	/**
	 * Validates that a column reference on a plain-table range (`range of a
	 * is table Name`) actually exists on the live table, the same role
	 * ValidateEntityPropertyExists plays for entity ranges.
	 *
	 * A plain-table range has no annotation-derived metadata to check a
	 * column against, so this is the one semantic check that introspects the
	 * live schema instead of static EntityStore metadata (see
	 * FindPropertyRange::tableHasColumn(), which already does the same kind
	 * of lookup for unqualified-property ambiguity resolution). When no
	 * DatabaseAdapter is available, or the table can't be introspected yet
	 * (e.g. a `create table` earlier in the same script/request), the check
	 * is skipped rather than raising a false positive — matching the
	 * permissive-fallback policy FindPropertyRange already established.
	 */
	class ValidateTablePropertyExists implements AstVisitorInterface {

		/** @var DatabaseAdapter|null */
		private ?DatabaseAdapter $databaseAdapter;

		/** @var array<string, array<string, mixed>|null> */
		private array $tableColumnsCache = [];

		/**
		 * @param DatabaseAdapter|null $databaseAdapter Live connection used to look up a table's real columns
		 */
		public function __construct(?DatabaseAdapter $databaseAdapter) {
			$this->databaseAdapter = $databaseAdapter;
		}

		/**
		 * Visit a node in the AST, validating column references on plain-table ranges.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 * @throws SemanticException Thrown when a referenced column doesn't exist on the table
		 */
		public function visitNode(AstInterface $node): void {
			// Only interested in column references on plain-table ranges
			if (!$node instanceof AstIdentifier || $node->getType() !== IdentifierType::TableProperty) {
				return;
			}

			// Only validate the direct column reference (e.g. `a.street`). A plain-table
			// range has no relations, so there's nothing meaningful to check beyond the
			// first segment.
			$parentNode = $node->getParent();

			if (!$parentNode instanceof AstIdentifier || $parentNode->getType() !== IdentifierType::TableRoot) {
				return;
			}

			// No live connection to check against — skip rather than guess.
			if ($this->databaseAdapter === null) {
				return;
			}

			$range = $parentNode->getRange();

			if (!$range instanceof AstRangeTable) {
				return;
			}

			$columns = $this->getColumns($range->getTableName());

			// Introspection failed (e.g. table doesn't exist yet) — skip rather than
			// turn a lookup failure into a false positive.
			if ($columns === null) {
				return;
			}

			$propertyName = $node->getName();

			if (!isset($columns[$propertyName])) {
				throw new SemanticException(
					"The column '{$propertyName}' does not exist in range '{$range->getName()}'. " .
					"Please check for typos or verify that the correct range is being referenced in the query."
				);
			}
		}

		/**
		 * Returns the given table's columns, keyed by column name, or null when
		 * introspection failed. Cached per table name for the lifetime of this visitor.
		 * @param string $tableName
		 * @return array<string, mixed>|null
		 */
		private function getColumns(string $tableName): ?array {
			if (!array_key_exists($tableName, $this->tableColumnsCache)) {
				try {
					$this->tableColumnsCache[$tableName] = $this->databaseAdapter->getColumns($tableName);
				} catch (\Throwable $e) {
					$this->tableColumnsCache[$tableName] = null;
				}
			}

			return $this->tableColumnsCache[$tableName];
		}
	}
