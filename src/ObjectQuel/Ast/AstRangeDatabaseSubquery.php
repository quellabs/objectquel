<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstRangeDatabaseSubquery
	 *
	 * Represents a derived table (subquery) range in an ObjectQuel query.
	 * Instead of referencing a physical table or entity, this range is defined
	 * by a nested AstRetrieve that produces a temporary result set.
	 *
	 * Both FROM-clause subqueries and JOIN-clause subqueries are represented
	 * by this class; whether it appears in FROM or JOIN is determined by
	 * whether a join property is set (inherited from AstRange).
	 *
	 * For direct table/entity references, use AstRangeDatabase instead.
	 */
	class AstRangeDatabaseSubquery extends AstRange {
		
		/**
		 * The subquery that defines this range as a derived table.
		 * @var AstRetrieve
		 */
		private AstRetrieve $query;
		
		/**
		 * The name given to this derived table in SQL: (SELECT ...) AS `tableName`.
		 * Distinct from the range alias (getName()), which is used within ObjectQuel.
		 * @var string|null
		 */
		private string $tableName;

		/**
		 * Whether this range should be included as a JOIN in the query.
		 * When false, the range is handled differently (e.g. inlined elsewhere).
		 * @var bool
		 */
		private bool $includeAsJoin;
		
		/**
		 * AstRangeDatabaseSubquery constructor.
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
			parent::__construct($name, $required, $joinProperty);
			$this->query = $query;
			$this->tableName = $tableName;
			$this->includeAsJoin = $includeAsJoin;
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
				$this->isRequired(),
				$this->includeAsJoin
			);
			
			$clone->setParent($this->getParent());
			
			return $clone;
		}
		
		// ========================================
		// Subquery Accessors
		// ========================================
		
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
		
		// ========================================
		// Join Inclusion Control
		// ========================================
		
		/**
		 * Control whether this range should be included as a JOIN clause.
		 * @param bool $includeAsJoin True to include as JOIN, false otherwise
		 * @return void
		 */
		public function setIncludeAsJoin(bool $includeAsJoin = true): void {
			$this->includeAsJoin = $includeAsJoin;
		}
		
		/**
		 * Check whether this range should be included as a JOIN clause.
		 * @return bool True if this range should be included as a JOIN
		 */
		public function includeAsJoin(): bool {
			return $this->includeAsJoin;
		}
	}