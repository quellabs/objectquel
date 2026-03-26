<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	/**
	 * Fluent builder that accumulates Phinx method calls for a single table
	 * and renders them as a properly indented code block.
	 *
	 * Usage:
	 *   $builder = new MigrationCodeBuilder('users', ["'id' => false"]);
	 *   $builder->addColumn('email', 'string', ["'limit' => 255", "'null' => false"]);
	 *   $builder->addIndex(['email'], ["'unique' => true", "'name' => 'uidx_users_email'"]);
	 *   echo $builder->create();
	 */
	class MigrationCodeBuilder {
		
		/** @var string[] Accumulated lines of the fluent Phinx call chain */
		private array $lines;
		
		/**
		 * @param string   $tableName    The table this builder targets
		 * @param string[] $tableOptions Pre-formatted option strings, e.g. ["'id' => false"]
		 */
		public function __construct(string $tableName, array $tableOptions = []) {
			$optionsStr  = empty($tableOptions) ? '' : ', [' . implode(', ', $tableOptions) . ']';
			$this->lines = ["        \$this->table('{$tableName}'{$optionsStr})"];
		}
		
		/**
		 * Append an addColumn() call to the chain.
		 *
		 * @param string   $name    Column name
		 * @param string   $type    Phinx column type (e.g. 'string', 'integer')
		 * @param string[] $options Pre-formatted option strings, e.g. ["'null' => false"]
		 */
		public function addColumn(string $name, string $type, array $options = []): static {
			$optionsStr    = empty($options) ? '' : ', [' . implode(', ', $options) . ']';
			$this->lines[] = "            ->addColumn('{$name}', '{$type}'{$optionsStr})";
			return $this;
		}
		
		/**
		 * Append a changeColumn() call to the chain.
		 *
		 * @param string   $name    Column name
		 * @param string   $type    New Phinx column type
		 * @param string[] $options Pre-formatted option strings
		 */
		public function changeColumn(string $name, string $type, array $options = []): static {
			$optionsStr    = empty($options) ? '' : ', [' . implode(', ', $options) . ']';
			$this->lines[] = "            ->changeColumn('{$name}', '{$type}'{$optionsStr})";
			return $this;
		}
		
		/**
		 * Append a removeColumn() call to the chain.
		 *
		 * @param string $name Column name
		 */
		public function removeColumn(string $name): static {
			$this->lines[] = "            ->removeColumn('{$name}')";
			return $this;
		}
		
		/**
		 * Append an addIndex() call to the chain.
		 *
		 * @param string[] $columns Column names to include in the index
		 * @param string[] $options Pre-formatted option strings, e.g. ["'unique' => true", "'name' => 'idx_foo'"]
		 */
		public function addIndex(array $columns, array $options): static {
			$columnsList   = "'" . implode("', '", $columns) . "'";
			$optionsStr    = implode(', ', $options);
			$this->lines[] = "            ->addIndex([{$columnsList}], [{$optionsStr}])";
			return $this;
		}
		
		/**
		 * Append a removeIndexByName() call to the chain.
		 *
		 * @param string $name Index name
		 */
		public function removeIndexByName(string $name): static {
			$this->lines[] = "            ->removeIndexByName('{$name}')";
			return $this;
		}
		
		/**
		 * Append a changePrimaryKey() call to the chain.
		 *
		 * @param string[] $keys Column names that form the new primary key
		 */
		public function changePrimaryKey(array $keys): static {
			$keysStr       = "'" . implode("', '", $keys) . "'";
			$this->lines[] = "            ->changePrimaryKey([{$keysStr}])";
			return $this;
		}
		
		/**
		 * Finalise the chain with ->create() and return the rendered code block.
		 * Use this when generating a new table.
		 */
		public function create(): string {
			return implode("\n", $this->lines) . "\n            ->create();";
		}
		
		/**
		 * Finalise the chain with ->update() and return the rendered code block.
		 * Use this when modifying an existing table.
		 */
		public function update(): string {
			return implode("\n", $this->lines) . "\n            ->update();";
		}
	}