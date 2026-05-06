<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Class AstRangeDatabaseTempTable
	 */
	class AstRangeDatabaseTempTable extends AstRangeDatabaseSubquery {
		
		/**
		 * The name given to this derived table in SQL: (SELECT ...) AS `tableName`.
		 * Distinct from the range alias (getName()), which is used within ObjectQuel.
		 * @var string
		 */
		private string $tableName;
		
		/**
		 * AstRangeDatabaseTempTable constructor.
		 * @param string $name The alias for this derived table in the query
		 * @param AstRetrieve $query The subquery defining this range
		 * @param string $tableName The name of the temporary table
		 * @param AstInterface|null $joinProperty Expression defining the join condition (null = FROM clause)
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 * @param bool $includeAsJoin Whether to include this range as a JOIN clause
		 */
		public function __construct(
			string        $name,
			AstRetrieve   $query,
			string        $tableName,
			?AstInterface $joinProperty = null,
			bool          $required = false,
			bool          $includeAsJoin = true
		) {
			parent::__construct($name, $query, $joinProperty, $required, $includeAsJoin);
			$this->tableName = $tableName;
		}
		
		/**
		 * Get the SQL alias used for this derived table: (SELECT ...) AS `tableName`.
		 * @return string
		 */
		public function getTableName(): string {
			return $this->tableName;
		}
		
		/**
		 * Set the SQL alias used for this derived table.
		 * @param string $tableName
		 * @return $this
		 */
		public function setTableName(string $tableName): static {
			$this->tableName = $tableName;
			return $this;
		}
	}