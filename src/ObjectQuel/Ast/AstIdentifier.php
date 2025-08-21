<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents an identifier node in the AST.
	 */
	class AstIdentifier extends Ast {
		
		/**
		 * @var string The actual identifier value.
		 */
		protected string $identifier;
		
		/**
		 * @var ?AstRange The attached range
		 */
		protected ?AstRange $range;
		
		/**
		 * @var ?AstIdentifier Next identifier in chain
		 */
		protected ?AstIdentifier $next = null;
		
		/**
		 * Constructor.
		 * @param string $identifier The identifier value
		 */
		public function __construct(string $identifier) {
			$this->identifier = $identifier;
			$this->range = null;
		}
		
		/**
		 * Accepteer een bezoeker om de AST te verwerken.
		 * @param AstVisitorInterface $visitor Bezoeker object voor AST-manipulatie.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			
			if ($this->hasNext()) {
				$this->getNext()->accept($visitor);
			}
		}
		
		/**
		 * Extracts and returns the entity name from the identifier.
		 * @return string|null The entity name or the full identifier if no property specified.
		 */
		public function getEntityName(): ?string {
			// If this identifier has a range that's attached to the database, use the entity from the range
			if ($this->range instanceof AstRangeDatabase) {
				return $this->range->getEntityName();
			}
			
			// Range is not a database range. Return null
			return null;
		}
		
		/**
		 * Extracts and returns the property name from the identifier.
		 * @return string The property name or an empty string if not specified.
		 */
		public function getName(): string {
			return $this->identifier;
		}
		
		/**
		 * Extracts and returns the property name from the identifier.
		 * @param string $name The property name or an empty string if not specified.
		 * @return void
		 */
		public function setName(string $name): void {
			$this->identifier = $name;
		}
		
		/**
		 * Chains all the names of identifiers together
		 * @return string
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
		
		/**
		 * Returns true if this node is the root node
		 * @return bool
		 */
		public function isRoot(): bool {
			return !$this->hasParent();
		}

		/**
		 * Returns true if the identifier contains another entry
		 * @return bool
		 */
		public function hasNext(): bool {
			return $this->next !== null;
		}
		
		/**
		 * Returns the next identifier in the chain
		 * @return AstIdentifier|null
		 */
		public function getNext(): ?AstIdentifier {
			return $this->next;
		}
		
		/**
		 * Sets the next identifier in the chain
		 * @param AstIdentifier|null $next
		 * @return void
		 */
		public function setNext(?AstIdentifier $next): void {
			$this->next = $next;
		}
		
		/**
		 * Returns true if a range was assigned to this identifier
		 * @return bool
		 */
		public function hasRange(): bool {
			return $this->range !== null;
		}
		
		/**
		 * Returns the attached range
		 * @return AstRange|null
		 */
		public function getRange(): ?AstRange {
			return $this->range;
		}
		
		/**
		 * Returns the first available range by traversing up the parent hierarchy.
		 * Searches from the current node upward until it finds a node with a range,
		 * or reaches the root node.
		 * @return AstRange|null The first range found in the parent hierarchy, or null if none exists
		 */
		public function getBaseRange(): ?AstRange {
			$current = $this;
			
			while ($current !== null) {
				$range = $current->getRange();
				
				if ($range !== null) {
					return $range;
				}
				
				$current = $current->getParent();
			}
			
			return null;
		}
		
		/**
		 * Sets or clears a range
		 * @param AstRange|null $range
		 * @return void
		 */
		public function setRange(?AstRange $range): void {
			$this->range = $range;
		}
		
		public function deepClone(): static {
			// Create new instance with the same identifier
			$clone = new static($this->identifier);
			
			// Set the range
			$clone->range = $this->range;
			
			// Clone the next identifier in the chain if it exists
			if ($this->next !== null) {
				$clone->next = $this->next->deepClone();
			}
			
			return $clone;
		}
	}