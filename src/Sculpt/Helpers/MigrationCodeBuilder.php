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
		
		/**
		 * Segments of output to render. Each entry is either:
		 *   ['type' => 'chain',   'lines' => string[]]  — a fluent $this->table(...)->...->create/update() block
		 *   ['type' => 'execute', 'sql'   => string]    — a standalone $this->execute('...'); statement
		 *
		 * execute() calls cannot be part of the fluent chain, so each one forces the
		 * current chain to be closed and a new one to be opened for any subsequent
		 * fluent calls.
		 *
		 * @var array<int, array{type: 'chain', lines: string[]}|array{type: 'execute', sql: string}>
		 */
		private array $segments;
		
		/** @var string Table name, needed to restart a fresh chain after execute() */
		private string $tableName;
		
		/** @var string[] Table options, needed to restart a fresh chain after execute() */
		private array $tableOptions;
		
		/**
		 * @param string   $tableName    The table this builder targets
		 * @param string[] $tableOptions Pre-formatted option strings, e.g. ["'id' => false"]
		 */
		public function __construct(string $tableName, array $tableOptions = []) {
			$this->tableName    = $tableName;
			$this->tableOptions = $tableOptions;
			$this->segments     = [['type' => 'chain', 'lines' => [$this->chainHeader()]]];
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
			$this->appendToChain("            ->addColumn('{$name}', '{$type}'{$optionsStr})");
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
			$this->appendToChain("            ->changeColumn('{$name}', '{$type}'{$optionsStr})");
			return $this;
		}
		
		/**
		 * Append a removeColumn() call to the chain.
		 *
		 * @param string $name Column name
		 */
		public function removeColumn(string $name): static {
			$this->appendToChain("            ->removeColumn('{$name}')");
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
			$this->appendToChain("            ->addIndex([{$columnsList}], [{$optionsStr}])");
			return $this;
		}
		
		/**
		 * Append a removeIndexByName() call to the chain.
		 *
		 * @param string $name Index name
		 */
		public function removeIndexByName(string $name): static {
			$this->appendToChain("            ->removeIndexByName('{$name}')");
			return $this;
		}
		
		/**
		 * Append a raw $this->execute() statement.
		 * Used for DDL that Phinx cannot express through its fluent API,
		 * such as PostgreSQL GIN indexes or generated tsvector columns.
		 * The execute call is emitted as a standalone statement outside the fluent
		 * chain; any subsequent fluent calls open a new chain automatically.
		 * @param string $sql Raw SQL to execute
		 */
		public function execute(string $sql): static {
			$this->segments[] = ['type' => 'execute', 'sql' => $sql];
			// Open a fresh chain so any subsequent fluent calls have a valid header.
			$this->segments[] = ['type' => 'chain', 'lines' => [$this->chainHeader()]];
			return $this;
		}
		
		/**
		 * Append a changePrimaryKey() call to the chain.
		 *
		 * @param string[] $keys Column names that form the new primary key
		 */
		public function changePrimaryKey(array $keys): static {
			$keysStr       = "'" . implode("', '", $keys) . "'";
			$this->appendToChain("            ->changePrimaryKey([{$keysStr}])");
			return $this;
		}
		
		/**
		 * Finalise the chain with ->create() and return the rendered code block.
		 * Use this when generating a new table.
		 */
		public function create(): string {
			return $this->render('create');
		}
		
		/**
		 * Finalise the chain with ->update() and return the rendered code block.
		 * Use this when modifying an existing table.
		 */
		public function update(): string {
			return $this->render('update');
		}
		
		/**
		 * Append a line to the current (last) chain segment.
		 * If the last segment is an execute, a new chain segment was already opened
		 * by execute(), so this always finds a chain segment at the end.
		 */
		private function appendToChain(string $line): void {
			$last = &$this->segments[array_key_last($this->segments)];
			assert($last['type'] === 'chain');
			$last['lines'][] = $line;
		}
		
		/**
		 * Build the $this->table(...) header line for a new chain segment.
		 */
		private function chainHeader(): string {
			$optionsStr = empty($this->tableOptions) ? '' : ', [' . implode(', ', $this->tableOptions) . ']';
			return "        \$this->table('{$this->tableName}'{$optionsStr})";
		}
		
		/**
		 * Render all segments into a single code block.
		 * Each chain segment is closed with ->create() or ->update(); each execute
		 * segment becomes a standalone $this->execute('...'); statement.
		 * Empty chain segments (header only, no fluent calls) are omitted.
		 * @param 'create'|'update' $terminator
		 */
		private function render(string $terminator): string {
			$parts = [];
			
			foreach ($this->segments as $segment) {
				if ($segment['type'] === 'execute') {
					$parts[] = "        \$this->execute('" . addslashes($segment['sql']) . "');";
				} else {
					// Omit chain segments that contain only the header and no fluent calls.
					if (count($segment['lines']) <= 1) {
						continue;
					}
					
					$parts[] = implode("\n", $segment['lines']) . "\n            ->{$terminator}();";
				}
			}
			
			return implode("\n", $parts);
		}
	}