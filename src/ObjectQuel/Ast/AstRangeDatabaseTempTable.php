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
		 * Create a deep copy of this range including all child nodes.
		 *
		 * Overrides AstRangeDatabaseSubquery::deepClone() — that implementation
		 * calls `new static(...)` with only its own constructor's parameter
		 * list (name, query, joinProperty, required, includeAsJoin), which
		 * doesn't supply this subclass's required $tableName, so cloning a
		 * AstRangeDatabaseTempTable through the inherited method fails with a
		 * TypeError. StageFactory::createDatabaseExecutionStage() clones every
		 * range in the query it's building a stage from — including a
		 * not-yet-materialized temp-table range, whose $tableName has already
		 * been assigned by DatabaseRangePromotor by that point — so this needs
		 * to preserve it explicitly.
		 * @return static A new instance with cloned child nodes
		 */
		public function deepClone(): static {
			$joinProperty = $this->getJoinProperty()?->deepClone();
			$query = $this->getQuery()->deepClone();

			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->getName(),
				$query,
				$this->tableName,
				$joinProperty,
				$this->isRequired(),
				$this->includeAsJoin()
			);

			$clone->setParent($this->getParent());
			return $clone;
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