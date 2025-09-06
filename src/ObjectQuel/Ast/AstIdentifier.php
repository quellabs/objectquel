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
		
		public function getPropertyName(): string {
			if ($this->getNext() !== null) {
				return $this->getNext()->getName();
			}
			
			return $this->getName();
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
		 * Returns true if the node has a parent, false if not
		 * @return bool
		 */
		public function hasParent(): bool {
			return is_a($this->getParent(), AstIdentifier::class);
		}
		
		/**
		 * Returns the parent aggregate if any
		 * @return AstInterface|null
		 */
		public function getParentAggregate(): ?AstInterface {
			$current = $this->getParent();
			
			while ($current !== null) {
				if (
					$current instanceof AstMin ||
					$current instanceof AstMax ||
					$current instanceof AstAvg ||
					$current instanceof AstAvgU ||
					$current instanceof AstSum ||
					$current instanceof AstSumU ||
					$current instanceof AstCount ||
					$current instanceof AstCountU ||
					$current instanceof AstAny
				) {
					return $current;
				}
				
				$current = $current->getParent();
			}
			
			return null;
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
		 * Returns true if this is a base identifier, false if not
		 * @return bool
		 */
		public function isBaseIdentifier(): bool {
			return !($this->getParent() instanceof AstIdentifier);
		}
		
		/**
		 * Returns the first available identifier by traversing up the parent hierarchy.
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
		
		/**
		 * Sets or clears a range
		 * @param AstRange|null $range
		 * @return void
		 */
		public function setRange(?AstRange $range): void {
			$this->range = $range;
		}
		
		/**
		 * Clone the node
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
	}