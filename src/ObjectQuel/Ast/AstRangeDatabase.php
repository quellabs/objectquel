<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstRangeDatabase
	 *
	 * Represents a direct table or entity reference in an ObjectQuel query.
	 * Handles JOIN relationships, including the join condition and whether
	 * the join is required (INNER) or optional (LEFT).
	 *
	 * For derived-table (subquery) ranges, use AstRangeDatabaseSubquery instead.
	 */
	class AstRangeDatabase extends AstRange {
		
		/**
		 * Entity associated with the range
		 * @var string|null
		 */
		private ?string $entityName;
		
		/**
		 * Whether this range should be included as a JOIN in the query.
		 * When false, the range is handled differently (e.g. inlined elsewhere).
		 * @var bool
		 */
		private bool $includeAsJoin;
		
		/**
		 * AstRangeDatabase constructor.
		 * @param string $name The alias for this range in the query
		 * @param string|null $entityName Name of the entity associated with this range
		 * @param AstInterface|null $joinProperty Expression defining the join condition
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 * @param bool $includeAsJoin Whether to include this range as a JOIN clause
		 */
		public function __construct(
			string        $name,
			?string       $entityName = null,
			?AstInterface $joinProperty = null,
			bool          $required = false,
			bool          $includeAsJoin = true
		) {
			parent::__construct($name, $required, $joinProperty);
			$this->entityName = $entityName;
			$this->includeAsJoin = $includeAsJoin;
		}

		/**
		 * Accept a visitor to process the AST.
		 * Ensures the visitor traverses all child nodes including joinProperty and query.
		 * @param AstVisitorInterface $visitor Visitor object for AST manipulation
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->getJoinProperty()?->accept($visitor);
		}
		
		/**
		 * Create a deep copy of this range including all child nodes
		 * @return static A new instance with cloned child nodes
		 */
		public function deepClone(): static {
			// Clone the join property. AstRange will give it a new parent in its constructor
			$joinProperty = $this->getJoinProperty()?->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->getName(),
				$this->entityName,
				$joinProperty,
				$this->isRequired(),
				$this->includeAsJoin
			);
			
			// Clone gets new parent
			$clone->setParent($this->getParent());
			
			// Return clone
			return $clone;
		}
		
		// ========================================
		// Entity Name Accessors
		// ========================================
		
		/**
		 * Get the entity name associated with this range.
		 * @return string|null The entity name
		 */
		public function getEntityName(): ?string {
			return $this->entityName;
		}
		
		/**
		 * Retrieve the entity name, assuming it has been fully resolved.
		 * @return string
		 * @throws \LogicException When the entity name has not been set
		 */
		public function getResolvedEntityName(): string {
			if ($this->entityName === null) {
				throw new \LogicException("Entity name has not been resolved");
			}
			
			return $this->entityName;
		}
		
		/**
		 * Set the entity name for this range.
		 * @param string $entityName The new entity name
		 * @return void
		 */
		public function setEntityName(string $entityName): void {
			$this->entityName = $entityName;
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