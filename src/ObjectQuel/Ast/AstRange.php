<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\IdentifierLocator;
	
	/**
	 * AstRange class is responsible for defining a range in the AST (Abstract Syntax Tree).
	 * This class represents a data source or table reference that can be used in queries,
	 * including information about how it should be joined with other ranges.
	 */
	class AstRange extends Ast {
		
		/**
		 * Alias for the range - used as a table alias in SQL queries
		 * @var string
		 */
		private string $name;
		
		/**
		 * True if the relation is optional (LEFT JOIN), false if required (INNER JOIN)
		 * This determines the type of join that will be generated in the SQL query
		 * @var bool
		 */
		private bool $required;
		
		/**
		 * Expression defining how to join this range to its parent
		 * Contains the join condition (e.g., "parent.id = child.parent_id")
		 * @var AstInterface|null
		 */
		private ?AstInterface $joinProperty;
		
		/**
		 * AstRange constructor.
		 * @param string $name The name/alias for this range (used as table alias)
		 * @param bool $required Whether this is a required join (INNER) or optional (LEFT)
		 */
		public function __construct(
			string        $name,
			bool          $required = false,
			?AstInterface $joinProperty = null
		) {
			$this->name = $name;
			$this->required = $required;
			$this->joinProperty = $joinProperty;
			
			if ($joinProperty) {
				$this->joinProperty->setParent($this);
			}
		}
		
		/**
		 * Get the alias for this range.
		 * @return string The alias of this range
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the entity name associated with this range
		 * @return string|null The entity name
		 */
		public function getEntityName(): ?string {
			return null;
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
		
		/**
		 * Set whether this relation is required.
		 * Makes the relation required (INNER JOIN) or optional (LEFT JOIN).
		 * This affects how the SQL query is generated and what results are returned.
		 * @param bool $required True for INNER JOIN (required), false for LEFT JOIN (optional)
		 * @return void
		 */
		public function setRequired(bool $required = true): void {
			$this->required = $required;
		}
		
		/**
		 * Check if the relation is required.
		 * @return bool True if this is a required relation (INNER JOIN)
		 */
		public function isRequired(): bool {
			return $this->required;
		}
		
		/**
		 * Create a deep copy of this range including all child nodes
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the join property
			$joinProperty = $this->getJoinProperty()?->deepClone();
			
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->required, $joinProperty);
			$clone->setParent($this);
			return $clone;
		}
	}