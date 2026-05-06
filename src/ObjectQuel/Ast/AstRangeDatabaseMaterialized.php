<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstRangeDatabaseMaterialized
	 */
	class AstRangeDatabaseMaterialized extends AstRange {
		
		/**
		 * The subquery that defines this range as a derived table.
		 * @var AstRetrieve
		 */
		private AstRetrieve $query;
		
		/**
		 * AstRangeDatabaseSubquery constructor.
		 * @param string $name The alias for this derived table in the query
		 * @param AstRetrieve $query The subquery defining this range
		 * @param AstInterface|null $joinProperty Expression defining the join condition (null = FROM clause)
		 * @param bool $required True for INNER JOIN, false for LEFT JOIN
		 */
		public function __construct(
			string        $name,
			AstRetrieve   $query,
			?AstInterface $joinProperty = null,
			bool          $required = false
		) {
			parent::__construct($name, $required, $joinProperty);
			$this->query = $query;
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
	}