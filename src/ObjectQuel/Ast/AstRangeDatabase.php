<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\IdentifierLocator;
	
	/**
	 * Class AstRangeDatabase
	 *
	 * Represents a database range in an ObjectQuel query, which can be either:
	 * - A direct table/entity reference
	 * - A subquery that produces a temporary result set
	 *
	 * Handles JOIN relationships, including the join conditions and whether
	 * the join is required (INNER) or optional (LEFT).
	 */
	class AstRangeDatabase extends AstRange {
		
		/**
		 * Entity associated with the range
		 * @var string|null
		 */
		private ?string $entityName;
		
		/**
		 * Physical table name associated with the range
		 * @var string|null
		 */
		private ?string $tableName;
		
		/**
		 * Expression defining how to join this range to its parent
		 * Contains the join condition (e.g., "parent.id = child.parent_id")
		 * @var AstInterface|null
		 */
		private ?AstInterface $joinProperty;
		
		/**
		 * Whether this range should be included as a JOIN in the query
		 * When false, this range might be handled as a subquery instead
		 * @var bool
		 */
		private bool $includeAsJoin;
		
		/**
		 * Subquery that defines this range as a temporary table
		 * When set, this range represents a derived table rather than a direct entity reference
		 * @var AstRetrieve|null
		 */
		private ?AstRetrieve $query;
		
		/**
		 * AstRangeDatabase constructor.
		 * @param string $name The alias for this range in the query
		 * @param string|null $entityName Name of the entity associated with this range
		 * @param AstInterface|null $joinProperty Expression defining the join condition
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 * @param bool $includeAsJoin Whether to include this range as a JOIN clause
		 */
		public function __construct(
			string $name,
			?string $entityName = null,
			?AstInterface $joinProperty = null,
			bool $required = false,
			bool $includeAsJoin = true
		) {
			parent::__construct($name, $required);
			$this->entityName = $entityName;
			$this->joinProperty = $joinProperty;
			$this->includeAsJoin = $includeAsJoin;
			$this->tableName = null;
			$this->query = null;
			
			if ($this->joinProperty) {
				$this->joinProperty->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor to process the AST.
		 * Ensures the visitor traverses all child nodes including joinProperty and query.
		 * @param AstVisitorInterface $visitor Visitor object for AST manipulation
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			if ($this->joinProperty !== null) {
				$this->joinProperty->accept($visitor);
			}
			
			if ($this->query !== null) {
				$this->query->accept($visitor);
			}
		}
		
		/**
		 * Create a deep copy of this range including all child nodes
		 * @return static A new instance with cloned child nodes
		 */
		public function deepClone(): static {
			$joinProperty = $this->joinProperty?->deepClone();
			$query = $this->query?->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->getName(),
				$this->entityName,
				$joinProperty,
				$this->isRequired(),
				$this->includeAsJoin
			);
			
			$clone->setParent($this->getParent());
			$clone->setTableName($this->tableName);
			$clone->setQuery($query);
			
			return $clone;
		}
		
		// ========================================
		// Entity and Table Name Accessors
		// ========================================
		
		/**
		 * Get the entity name associated with this range
		 * @return string|null The entity name
		 */
		public function getEntityName(): ?string {
			return $this->entityName;
		}
		
		/**
		 * Set the entity name for this range
		 * @param string $entityName The new entity name
		 * @return void
		 */
		public function setEntityName(string $entityName): void {
			$this->entityName = $entityName;
		}
		
		/**
		 * Get the physical table name for this range
		 * @return string|null The table name
		 */
		public function getTableName(): ?string {
			return $this->tableName;
		}
		
		/**
		 * Set the physical table name for this range
		 * @param string|null $tableName The table name
		 * @return AstRangeDatabase This instance for method chaining
		 */
		public function setTableName(?string $tableName): AstRangeDatabase {
			$this->tableName = $tableName;
			return $this;
		}
		
		// ========================================
		// Join Property Accessors
		// ========================================
		
		/**
		 * Get the expression that defines how to join this range
		 * @return AstInterface|null The join condition expression
		 */
		public function getJoinProperty(): ?AstInterface {
			return $this->joinProperty;
		}
		
		/**
		 * Set the expression that defines how to join this range
		 * @param AstInterface|null $joinExpression The join condition expression
		 * @return void
		 */
		public function setJoinProperty(?AstInterface $joinExpression): void {
			$this->joinProperty = $joinExpression;
		}
		
		/**
		 * Check if the join property contains a reference to a specific entity property
		 * @param string $entityName The entity name to search for
		 * @param string $property The property name to search for
		 * @return bool True if the join property references the given entity.property
		 */
		public function hasJoinProperty(string $entityName, string $property): bool {
			if ($this->joinProperty === null) {
				return false;
			}
			
			try {
				$findVisitor = new IdentifierLocator($entityName, $property);
				$this->joinProperty->accept($findVisitor);
				return false;
			} catch (\Exception $exception) {
				return true;
			}
		}
		
		// ========================================
		// Join Inclusion Control
		// ========================================
		
		/**
		 * Control whether this range should be included as a JOIN clause
		 * When false, the range might be handled as a subquery or other construct
		 * @param bool $includeAsJoin True to include as JOIN, false otherwise
		 * @return void
		 */
		public function setIncludeAsJoin(bool $includeAsJoin = true): void {
			$this->includeAsJoin = $includeAsJoin;
		}
		
		/**
		 * Check whether this range should be included as a JOIN clause
		 * @return bool True if this range should be included as a JOIN
		 */
		public function includeAsJoin(): bool {
			return $this->includeAsJoin;
		}
		
		// ========================================
		// Subquery Accessors
		// ========================================
		
		/**
		 * Check if this range is defined by a subquery
		 * @return bool True if this range has an associated subquery
		 */
		public function containsQuery(): bool {
			return $this->query !== null;
		}
		
		/**
		 * Get the subquery that defines this range
		 * @return AstRetrieve|null The subquery, or null if this is a direct table reference
		 */
		public function getQuery(): ?AstRetrieve {
			return $this->query;
		}
		
		/**
		 * Set the subquery that defines this range
		 * When set, this range represents a derived table rather than a direct entity
		 * @param AstRetrieve|null $query The subquery to use
		 * @return AstRange This instance for method chaining
		 */
		public function setQuery(?AstRetrieve $query): AstRange {
			$this->query = $query;
			return $this;
		}
	}