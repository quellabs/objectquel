<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstRangeDatabaseTempTable
	 */
	class AstRangeDatabaseTempTable extends AstRange {
		
		/**
		 * The subquery that defines this range as a derived table.
		 * @var AstRetrieve
		 */
		private AstRetrieve $query;
		
		/**
		 * The name given to this derived table in SQL: (SELECT ...) AS `tableName`.
		 * Distinct from the range alias (getName()), which is used within ObjectQuel.
		 * @var string
		 */
		private string $tableName;
		
		/**
		 * AstRangeDatabaseSubquery constructor.
		 * @param string $name The alias for this derived table in the query
		 * @param AstRetrieve $query The subquery defining this range
		 * @param string $tableName The name of the temporary table
		 * @param AstInterface|null $joinProperty Expression defining the join condition (null = FROM clause)
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 */
		public function __construct(
			string        $name,
			AstRetrieve   $query,
			string        $tableName,
			?AstInterface $joinProperty = null,
			bool          $required = false
		) {
			parent::__construct($name, $required, $joinProperty);
			$this->query = $query;
			$this->tableName = $tableName;
		}
		
		/**
		 * Accept a visitor, ensuring it traverses the join property and the subquery.
		 * @param AstVisitorInterface $visitor
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			$this->getJoinProperty()?->accept($visitor);
			$this->query->accept($visitor);
		}
		
		/**
		 * Create a deep copy of this range including all child nodes.
		 * @return static A new instance with cloned child nodes
		 */
		public function deepClone(): static {
			$joinProperty = $this->getJoinProperty()?->deepClone();
			$query = $this->query->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->getName(),
				$query,
				$this->tableName,
				$joinProperty,
				$this->isRequired()
			);
			
			$clone->setParent($this->getParent());
			
			return $clone;
		}
		
		/**
		 * Get the subquery that defines this derived table range.
		 * @return AstRetrieve
		 */
		public function getQuery(): AstRetrieve {
			return $this->query;
		}
		
		/**
		 * Replace the subquery that defines this range.
		 * @param AstRetrieve $query
		 * @return $this
		 */
		public function setQuery(AstRetrieve $query): static {
			$this->query = $query;
			return $this;
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