<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\FindIdentifier;
	
	/**
	 * Class AstRange
	 * AstRange class is responsible for defining a range in the AST (Abstract Syntax Tree).
	 */
	class AstRangeDatabase extends AstRange {
		
		// Entity associated with the range
		private string $entityName;
		
		// The via string indicates on which field to join (LEFT JOIN etc)
		private ?AstInterface $joinProperty;
		
		/**
		 * True if the range should be included as a JOIN in the query
		 * When false, this range might be handled differently (e.g., as a subquery)
		 * @var bool
		 */
		private bool $includeAsJoin;
		
		/**
		 * AstRange constructor.
		 * @param string $name The name for this range.
		 * @param string $entityName Name of the entity associated with this range.
		 * @param AstInterface|null $joinProperty
		 * @param bool $required True if the relationship is required. E.g. it concerns an INNER JOIN. False for LEFT JOIN.
		 * @param bool $includeAsJoin Whether to include this range as a JOIN clause
		 */
		public function __construct(string $name, string $entityName, ?AstInterface $joinProperty=null, bool $required=false, bool $includeAsJoin = true) {
			parent::__construct($name, $required);
			$this->entityName = $entityName;
			$this->joinProperty = $joinProperty;
			$this->includeAsJoin = $includeAsJoin;
			
			if ($this->joinProperty) {
				$this->joinProperty->setParent($this);
			}
		}
		
		/**
		 * Accept a visitor to process the AST.
		 * @param AstVisitorInterface $visitor Visitor object for AST manipulation.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);  // Accept the visitor on parent class first
			
			if (!is_null($this->joinProperty)) {
				$this->joinProperty->accept($visitor); // And accept the 'via' property
			}
		}
		
		/**
		 * Get the AST of the entity associated with this range.
		 * @return string The name of the entity.
		 */
		public function getEntityName(): string {
			return $this->entityName;
		}
		
		/**
		 * Sets a new entity name
		 * @param string $entityName
		 * @return void
		 */
		public function setEntityName(string $entityName): void {
			$this->entityName = $entityName;
		}
		
		/**
		 * The via expression indicates on which fields to join
		 * @return AstInterface|null
		 */
		public function getJoinProperty(): ?AstInterface {
			return $this->joinProperty;
		}
		
		/**
		 * The via expression indicates on which fields to join
		 * @param AstInterface|null $joinExpression
		 * @return void
		 */
		public function setJoinProperty(?AstInterface $joinExpression): void {
			$this->joinProperty = $joinExpression;
		}
		
		/**
		 * Returns true if the range expression contains the given property
		 * @param string $entityName
		 * @param string $property
		 * @return bool
		 */
		public function hasJoinProperty(string $entityName, string $property): bool {
			// False if the property doesn't exist
			if (is_null($this->joinProperty)) {
				return false;
			}
			
			try {
				$findVisitor = new FindIdentifier($entityName, $property);
				$this->joinProperty->accept($findVisitor);
				return false;
			} catch (\Exception $exception) {
				return true;
			}
		}
		
		/**
		 * Controls whether this range should be included as a JOIN clause in the
		 * generated SQL. When false, the range might be handled as a subquery
		 * or other construct instead.
		 * @param bool $includeAsJoin True to include as JOIN, false otherwise
		 * @return void
		 */
		public function setIncludeAsJoin(bool $includeAsJoin = true): void {
			$this->includeAsJoin = $includeAsJoin;
		}
		
		/**
		 * Returns whether this range should be included as a JOIN clause
		 * in the SQL query generation process.
		 * @return bool True if this range should be included as a JOIN
		 */
		public function includeAsJoin(): bool {
			return $this->includeAsJoin;
		}
		
		public function deepClone(): static {
			if ($this->joinProperty) {
				$joinProperty = $this->joinProperty->deepClone();
			} else {
				$joinProperty = null;
			}
			
			return new static($this->getName(), $this->getEntityName(), $joinProperty, $this->isRequired(), $this->includeAsJoin());
		}
	}