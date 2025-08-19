<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\FindIdentifier;
	
	/**
	 * Class AstRange
	 *
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
		 * AstRange constructor.
		 * @param string $name The name/alias for this range (used as table alias)
		 * @param bool $required Whether this is a required join (INNER) or optional (LEFT)
		 */
		public function __construct(string $name, bool $required = false) {
			$this->name = $name;
			$this->required = $required;
		}
		
		/**
		 * Get the alias for this range.
		 * @return string The alias of this range
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the join property expression.
		 * @return AstInterface|null The join condition expression, or null if no specific join property
		 */
		public function getJoinProperty(): ?AstInterface {
			return null;
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
	}