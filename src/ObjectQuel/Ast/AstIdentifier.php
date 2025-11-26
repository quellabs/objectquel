<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents an identifier node in the AST.
	 *
	 * Identifiers can be chained (e.g., "user.address.city") and can be associated
	 * with ranges (data sources like entities, temporary tables, or JSON sources).
	 */
	class AstIdentifier extends Ast {
		
		/**
		 * @var string The actual identifier value.
		 */
		protected string $identifier;
		
		/**
		 * @var ?AstRange The attached range (data source).
		 */
		protected ?AstRange $range;
		
		/**
		 * @var ?AstIdentifier Next identifier in chain for property access (e.g., "user.id").
		 */
		protected ?AstIdentifier $next = null;
		
		// =========================================================================
		// CONSTRUCTION & VISITOR
		// =========================================================================
		
		/**
		 * Constructor.
		 * @param string $identifier The identifier value
		 */
		public function __construct(string $identifier) {
			$this->identifier = $identifier;
			$this->range = null;
		}
		
		/**
		 * Accept a visitor to process the AST.
		 * @param AstVisitorInterface $visitor Visitor object for AST manipulation.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			if ($this->hasNext()) {
				$this->getNext()->accept($visitor);
			}
		}
		
		/**
		 * Clone the node and its entire chain.
		 * @return static
		 */
		public function deepClone(): static {
			// Create new instance with the same identifier
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->identifier);
			
			// Set the range
			$clone->range = $this->range;
			
			// Clone the next identifier in the chain if it exists
			if ($this->next !== null) {
				$clone->next = $this->next->deepClone();
			}
			
			return $clone;
		}
		
		// =========================================================================
		// BASIC NAME ACCESS
		// =========================================================================
		
		/**
		 * Returns the identifier name for this specific node.
		 * @return string The identifier name (e.g., "user" in "user.id").
		 */
		public function getName(): string {
			return $this->identifier;
		}
		
		/**
		 * Sets the identifier name for this specific node.
		 * @param string $name The new identifier name.
		 * @return void
		 */
		public function setName(string $name): void {
			$this->identifier = $name;
		}
		
		/**
		 * Returns the property name (last segment in the chain).
		 * For "user.address.city", returns "city".
		 * @return string The property name.
		 */
		public function getPropertyName(): string {
			if ($this->getNext() !== null) {
				return $this->getNext()->getName();
			}
			
			return $this->getName();
		}
		
		/**
		 * Returns the complete identifier path with all segments joined by dots.
		 * For a chain like "user.address.city", returns "user.address.city".
		 * @return string The complete dotted name.
		 */
		public function getCompleteName(): string {
			// Start with base identifier
			$current = $this;
			$name = $this->getName();
			
			// Build the property access chain by walking the linked properties
			while ($current->getNext() !== null) {
				// Fetch next identifier in the list
				$current = $current->getNext();
				
				// Add name to list
				$name .= "." . $current->getName();
			}
			
			// Return the name
			return $name;
		}
		
		// =========================================================================
		// CHAIN NAVIGATION
		// =========================================================================
		
		/**
		 * Returns true if this identifier has a next segment in the chain.
		 * @return bool
		 */
		public function hasNext(): bool {
			return $this->next !== null;
		}
		
		/**
		 * Returns the next identifier in the chain.
		 * @return AstIdentifier|null
		 */
		public function getNext(): ?AstIdentifier {
			return $this->next;
		}
		
		/**
		 * Sets the next identifier in the chain.
		 * @param AstIdentifier|null $next
		 * @return void
		 */
		public function setNext(?AstIdentifier $next): void {
			$this->next = $next;
		}
		
		// =========================================================================
		// PARENT HIERARCHY
		// =========================================================================
		
		/**
		 * Returns true if this node is the root node (has no parent identifier).
		 * @return bool
		 */
		public function isRoot(): bool {
			return !$this->hasParent();
		}
		
		/**
		 * Returns true if the node has an identifier as parent.
		 * @return bool
		 */
		public function hasParent(): bool {
			return is_a($this->getParent(), AstIdentifier::class);
		}
		
		/**
		 * Returns true if this is the base (first) identifier in the chain.
		 * For "user.address.city", only "user" is the base identifier.
		 * @return bool
		 */
		public function isBaseIdentifier(): bool {
			return !($this->getParent() instanceof AstIdentifier);
		}
		
		/**
		 * Returns the base (first) identifier by traversing up the parent hierarchy.
		 * For "user.address.city", returns the "user" identifier node.
		 * @return AstIdentifier|null
		 */
		public function getBaseIdentifier(): ?AstIdentifier {
			$current = $this;
			
			while (!$current->isBaseIdentifier()) {
				// Fetch parent
				$parent = $current->getParent();
				
				// Type guard: ensure parent is an AstIdentifier
				// Not really needed, but added to make PHPStan happy
				if (!($parent instanceof AstIdentifier)) {
					return null;
				}
				
				$current = $parent;
			}
			
			return $current;
		}
		
		// =========================================================================
		// RANGE MANAGEMENT
		// =========================================================================
		
		/**
		 * Returns true if a range (data source) was assigned to this identifier.
		 * @return bool
		 */
		public function hasRange(): bool {
			return $this->range !== null;
		}
		
		/**
		 * Returns the range (data source) directly attached to this identifier.
		 * @return AstRange|null
		 */
		public function getRange(): ?AstRange {
			return $this->range;
		}
		
		/**
		 * Sets or clears the range (data source) for this identifier.
		 * @param AstRange|null $range
		 * @return void
		 */
		public function setRange(?AstRange $range): void {
			$this->range = $range;
		}
		
		/**
		 * Returns the range this identifier belongs to by traversing to the base identifier.
		 * For "user.id", returns the range attached to "user".
		 * For "c.title", returns the range attached to "c".
		 * @return AstRange|null The range this identifier chain belongs to.
		 */
		public function getSourceRange(): ?AstRange {
			$baseIdentifier = $this->getBaseIdentifier();
			return $baseIdentifier?->getRange();
		}
		
		/**
		 * Returns the name of the range this identifier belongs to.
		 * For "user.id", returns "user".
		 * For "c.title", returns "c".
		 * @return string|null The range name, or null if no range attached.
		 */
		public function getSourceRangeName(): ?string {
			return $this->getSourceRange()?->getName();
		}
		
		// =========================================================================
		// RANGE TYPE DETECTION
		// =========================================================================
		
		/**
		 * Returns true if this identifier belongs to a temporary table range (subquery).
		 * @return bool
		 */
		public function isFromTemporaryTable(): bool {
			$range = $this->getSourceRange();
			
			if (!$range instanceof AstRangeDatabase) {
				return false;
			}
			
			return $range->containsQuery();
		}
		
		/**
		 * Returns true if this identifier belongs to an entity range (database table).
		 * @return bool
		 */
		public function isFromEntity(): bool {
			$range = $this->getSourceRange();
			
			if (!$range instanceof AstRangeDatabase) {
				return false;
			}
			
			if ($range->containsQuery()) {
				return false;
			}
			
			return $range->getEntityName() !== null;
		}
		
		/**
		 * Returns true if this identifier belongs to a JSON source range.
		 * @return bool
		 */
		public function isFromJsonSource(): bool {
			return $this->getSourceRange() instanceof AstRangeJsonSource;
		}
		
		// =========================================================================
		// RANGE DATA ACCESS
		// =========================================================================
		
		/**
		 * Returns the entity name if this identifier belongs to an entity range.
		 * Returns null for temporary tables and non-entity ranges.
		 * @return string|null The entity name or null.
		 */
		public function getEntityName(): ?string {
			if (!$this->isFromEntity()) {
				return null;
			}
			
			$range = $this->getSourceRange();
			return $range->getEntityName();
		}
		
		/**
		 * Returns the temporary table query if this identifier belongs to a temporary table.
		 * Returns null for entity ranges and non-database ranges.
		 * @return AstRetrieve|null The temporary table query, or null.
		 */
		public function getTemporaryTableQuery(): ?AstRetrieve {
			if (!$this->isFromTemporaryTable()) {
				return null;
			}
			
			$range = $this->getSourceRange();
			return $range->getQuery();
		}
	}