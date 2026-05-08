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
		 * Whether this range should be included as a JOIN in the query.
		 * When false, the range is handled differently (e.g. inlined elsewhere).
		 * @var bool
		 */
		private bool $includeAsJoin;
		
		/**
		 * AstRangeDatabaseSubquery constructor.
		 * @param string $name The alias for this derived table in the query
		 * @param AstRetrieve $query The subquery defining this range
		 * @param AstInterface|null $joinProperty Expression defining the join condition (null = FROM clause)
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 * @param bool $includeAsJoin Whether to include this range as a JOIN clause
		 */
		public function __construct(
			string        $name,
			AstRetrieve   $query,
			?AstInterface $joinProperty = null,
			bool          $required = false,
			bool          $includeAsJoin = true
		) {
			parent::__construct($name, $required, $joinProperty);
			$this->query = $query;
			$this->includeAsJoin = $includeAsJoin;
		}

		/**
		 * Create a deep copy of this range including all child nodes.
		 * @return static A new instance with cloned child nodes
		 */
		public function deepClone(): static {
			// Clone the join property
			$joinProperty = $this->getJoinProperty()?->deepClone();
			
			// Clone the query
			$query = $this->query->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->getName(),
				$query,
				$joinProperty,
				$this->isRequired(),
				$this->includeAsJoin
			);
			
			// Set clone parent
			$clone->setParent($this->getParent());
			
			// Return clone
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