<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsJsonIdentifier;
	
	/**
	 * Class AstRetrieve
	 *
	 * Represents a retrieve operation in the AST (Abstract Syntax Tree).
	 * This class models a SELECT statement with all its components including:
	 * - Values to retrieve (SELECT clause)
	 * - Data sources/ranges (FROM clause)
	 * - Filtering conditions (WHERE clause)
	 * - Sorting specifications (ORDER BY clause)
	 * - Grouping specifications (GROUP BY clause)
	 * - Pagination controls (LIMIT/OFFSET)
	 * - Uniqueness constraints (DISTINCT)
	 * - Compiler directives and macros
	 */
	class AstRetrieve extends Ast {
		
		/** @var array Compiler directives that control query compilation behavior */
		protected array $directives;
		
		/** @var array Values/expressions to be retrieved (SELECT clause) */
		protected array $values;
		
		/** @var array Named macros that can be referenced in the query */
		protected array $macros;
		
		/** @var array Data source ranges (FROM and JOIN clauses) */
		protected array $ranges;
		
		/** @var AstInterface|null Filtering conditions (WHERE clause) */
		protected ?AstInterface $conditions;
		
		/** @var array Sorting specifications with AST nodes and direction */
		protected array $sort;
		
		/** @var bool Whether sorting should be handled in application logic instead of database */
		protected bool $sort_in_application_logic;
		
		/** @var bool Whether to return only unique results (DISTINCT) */
		protected bool $unique;
		
		/** @var int|null Starting offset for pagination (OFFSET) */
		protected ?int $window;
		
		/** @var int|null Maximum number of results to return (LIMIT) */
		protected ?int $window_size;
		
		/** @var array Grouping specifications (GROUP BY clause) */
		protected array $group_by;
		
		/**
		 * AstRetrieve constructor.
		 * @param array $directives Compiler directives for query optimization
		 * @param AstRangeDatabase[] $ranges Data source ranges (tables, subqueries)
		 * @param bool $unique True if the results should be unique (DISTINCT), false otherwise
		 */
		public function __construct(array $directives, array $ranges, bool $unique) {
			$this->directives = $directives;
			$this->values = [];
			$this->macros = [];
			$this->conditions = null;
			$this->ranges = $ranges;
			$this->unique = $unique;
			$this->sort = [];
			$this->sort_in_application_logic = false;
			$this->window = null;
			$this->window_size = null;
			$this->group_by = [];
			
			// Establish parent-child relationships for all ranges
			foreach($this->ranges as $range) {
				$range->setParent($this);
			}
		}
		
		/**
		 * Accepts a visitor to perform operations on this node and all its children.
		 * @param AstVisitorInterface $visitor The visitor to accept
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			// Process all ranges first (FROM and JOIN clauses)
			foreach($this->ranges as $value) {
				$value->accept($visitor);
			}
			
			// Process the node itself excluding ranges
			$this->acceptWithoutRanges($visitor);
		}
		
		/**
		 * Accepts a visitor to perform operations on this node excluding ranges.
		 * @param AstVisitorInterface $visitor The visitor to accept
		 * @return void
		 */
		public function acceptWithoutRanges(AstVisitorInterface $visitor): void {
			// Let the parent class handle basic visitor operations
			parent::accept($visitor);
			
			// Process all values in the SELECT clause
			foreach($this->values as $value) {
				$value->accept($visitor);
			}
			
			// Process conditions if they exist (WHERE clause)
			if ($this->conditions !== null) {
				$this->conditions->accept($visitor);
			}
			
			// Process sorting specifications (ORDER BY clause)
			foreach($this->sort as $s) {
				$s['ast']->accept($visitor);
			}
			
			// Process all defined macros
			foreach($this->macros as $macro) {
				$macro->accept($visitor);
			}
		}
		
		/**
		 * Add a value to be retrieved in the SELECT clause.
		 * @param AstInterface $ast The value/expression to add to the SELECT clause
		 */
		public function addValue(AstInterface $ast): void {
			$ast->setParent($this);

			$this->values[] = $ast;
		}
		
		/**
		 * Get all values to be retrieved in the SELECT clause.
		 * @return AstAlias[] The array of values/expressions
		 */
		public function getValues(): array {
			return $this->values;
		}
		
		/**
		 * Replace all current values with a new set of values.
		 * @param array $values New array of values to retrieve
		 * @return void
		 */
		public function setValues(array $values): void {
			// Establish parent relationships for all new values
			foreach($values as $value) {
				$value->setParent($this);
			}
			
			$this->values = $values;
		}
		
		/**
		 * Get the filtering conditions for this retrieve operation.
		 * @return AstInterface|null The WHERE clause conditions or null if none
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Set the filtering conditions for this retrieve operation.
		 * @param AstInterface|null $ast The WHERE clause conditions, or null to remove conditions
		 */
		public function setConditions(?AstInterface $ast): void {
			$ast?->setParent($this);
			$this->conditions = $ast;
		}
		
		/**
		 * Returns all non-database ranges (e.g., subqueries, temporary tables).
		 * @return array Array of ranges that are not database tables
		 */
		public function getOtherRanges(): array {
			return array_filter($this->ranges, function($range) {
				return !$range instanceof AstRangeDatabase;
			});
		}
		
		/**
		 * Returns all ranges used in the retrieve statement.
		 * @return array All ranges in the FROM and JOIN clauses
		 */
		public function getRanges(): array {
			return $this->ranges;
		}
		
		/**
		 * Replaces all current ranges with a new set.
		 * @param array $ranges New array of ranges to use as data sources
		 * @return void
		 */
		public function setRanges(array $ranges): void {
			// Set new ranges array
			$this->ranges = $ranges;
			
			// Establish parent relationships for all new ranges
			foreach($this->ranges as $range) {
				$range->setParent($this);
			}
		}
		
		/**
		 * Adds a new database range to the existing range list.
		 * @param AstRangeDatabase $range New database range to add (typically a JOIN)
		 * @return void
		 */
		public function addRange(AstRangeDatabase $range): void {
			$range->setParent($this);
			$this->ranges[] = $range;
		}
		
		/**
		 * Checks if a specific range exists in the current range list.
		 * @param AstRange $rangeToCheck The range to search for
		 * @return bool True if the range exists, false otherwise
		 */
		public function hasRange(AstRange $rangeToCheck): bool {
			foreach($this->ranges as $range) {
				if ($range->getName() === $rangeToCheck->getName()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Removes a specific range from the range list.
		 * @param AstRange $rangeToRemove The range to remove
		 * @return void
		 */
		public function removeRange(AstRange $rangeToRemove): void {
			$result = [];
			
			// Filter out the range to remove
			foreach($this->ranges as $range) {
				if ($range->getName() !== $rangeToRemove->getName()) {
					$result[] = $range;
				}
			}
			
			$this->ranges = $result;
		}
		
		/**
		 * Returns the main database range (the primary FROM table).
		 * @return AstRangeDatabase|null The main database range or null if not found
		 */
		public function getMainDatabaseRange(): ?AstRangeDatabase {
			foreach ($this->getRanges() as $range) {
				if ($range instanceof AstRangeDatabase && $range->getJoinProperty() === null) {
					return $range;
				}
			}
			
			return null;
		}
		
		/**
		 * Returns whether the query should return unique results only.
		 * @return bool True if DISTINCT is applied, false otherwise
		 */
		public function isUnique(): bool {
			return $this->unique;
		}
		
		/**
		 * Returns all defined macros in this query.
		 * @return array Associative array of macro names to AST nodes
		 */
		public function getMacros(): array {
			return $this->macros;
		}
		
		/**
		 * Adds a new macro definition.
		 * @param string $name The macro name
		 * @param AstInterface|null $ast The AST node representing the macro value
		 * @return void
		 */
		public function addMacro(string $name, ?AstInterface $ast): void {
			$this->macros[$name] = $ast;
		}
		
		/**
		 * Checks if a macro with the given name exists.
		 * @param string $name The macro name to check
		 * @return bool True if the macro exists, false otherwise
		 */
		public function macroExists(string $name): bool {
			return isset($this->macros[$name]);
		}
		
		/**
		 * Sets the sorting specifications for the ORDER BY clause.
		 * @param array $sortArray Array of sort specifications, each containing 'ast' and direction
		 * @return void
		 */
		public function setSort(array $sortArray): void {
			$this->sort = $sortArray;
		}
		
		/**
		 * Returns the current sorting specifications.
		 * @return array Array of sort specifications for the ORDER BY clause
		 */
		public function getSort(): array {
			return $this->sort;
		}
		
		/**
		 * Sets the pagination window
		 * @param int $window
		 * @return void
		 */
		public function setWindow(int $window): void {
			$this->window = $window;
		}
		
		/**
		 * Returns the pagination window.
		 * @return int|null
		 */
		public function getWindow(): ?int {
			return $this->window;
		}
		
		/**
		 * Sets the maximum number of results to return.
		 * @param int $windowSize
		 * @return void
		 */
		public function setWindowSize(int $windowSize): void {
			$this->window_size = $windowSize;
		}
		
		/**
		 * Returns the maximum number of results to return.
		 * @return int|null The limit value or null if not set
		 */
		public function getWindowSize(): ?int {
			return $this->window_size;
		}
		
		/**
		 * Sets the uniqueness constraint for the query.
		 * @param bool $unique True to apply DISTINCT, false to allow duplicates
		 * @return void
		 */
		public function setUnique(bool $unique): void {
			$this->unique = $unique;
		}
		
		/**
		 * Returns the uniqueness constraint setting.
		 * @return bool True if DISTINCT is applied, false otherwise
		 */
		public function getUnique(): bool {
			return $this->unique;
		}
		
		/**
		 * Returns all compiler directives for this query.
		 * Directives control how the query is compiled and optimized.
		 * @return array Associative array of directive names to values
		 */
		public function getDirectives(): array {
			return $this->directives;
		}
		
		/**
		 * Returns a specific compiler directive value.
		 * @param string $name The directive name
		 * @return mixed The directive value or null if not found
		 */
		public function getDirective(string $name): mixed {
			return $this->directives[$name] ?? null;
		}
		
		/**
		 * Returns whether sorting should be handled in application logic.
		 * @return bool True if application-level sorting is required, false for database sorting
		 */
		public function getSortInApplicationLogic(): bool {
			return $this->sort_in_application_logic;
		}
		
		/**
		 * Checks if any sorting criteria contains a JSON identifier.
		 * @return bool True if sort contains JSON identifiers, false if sort is empty or contains no JSON
		 */
		public function sortContainsJsonIdentifier(): bool {
			// No sorting means no JSON identifiers
			if (empty($this->sort)) {
				return false;
			}
			
			// Create a visitor to detect JSON identifiers
			$visitor = new ContainsJsonIdentifier();
			
			try {
				// Check each sort expression for JSON identifiers
				foreach ($this->sort as $value) {
					$value["ast"]->accept($visitor);
				}
			} catch (\Exception $e) {
				// If an exception occurs during processing,
				// assume there is a JSON identifier and return true
				return true;
			}
			
			// No JSON identifiers found
			return false;
		}
		
		/**
		 * Sets whether sorting should be handled in application logic.
		 * @param bool $setSort True to enable application-level sorting, false for database sorting
		 * @return void
		 */
		public function setSortInApplicationLogic(bool $setSort): void {
			$this->sort_in_application_logic = $setSort;
		}
		
		/**
		 * Determines if this is a single-range query (no JOINs).
		 * @param bool $useIncludedTag If true, only count ranges marked for inclusion in JOINs
		 * @return bool True if only one range is involved, false for multi-table queries
		 */
		public function isSingleRangeQuery(bool $useIncludedTag=false): bool {
			if ($useIncludedTag) {
				// Count only ranges that should be included as joins
				$filter = array_filter($this->ranges, function($range) {
					return $range->includeAsJoin();
				});

				return count($filter) === 1;
			}
			
			// Count all ranges
			return count($this->ranges) === 1;
		}

		/**
		 * Determines which part of the query contains a specific AST node.
		 * This is useful for error reporting and optimization decisions.
		 * @param AstInterface $ast The AST node to locate
		 * @return string|null The query section ("select", "conditions", "order_by") or null if not found
		 */
		public function getLocationOfChild(AstInterface $ast): ?string {
			// Check if it's in the SELECT clause
			foreach($this->values as $value) {
				if ($ast->isAncestorOf($value)) {
					return "select";
				}
			}
			
			// Check if it's in the WHERE clause
			if ($this->conditions !== null) {
				if ($ast->isAncestorOf($this->conditions)) {
					return "conditions";
				}
			}
			
			// Check if it's in the ORDER BY clause
			foreach($this->sort as $value) {
				if ($ast['ast']->isAncestorOf($value)) {
					return "order_by";
				}
			}
			
			return null;
		}
		
		/**
		 * Returns the GROUP BY specifications.
		 * @return array Array of grouping expressions
		 */
		public function getGroupBy(): array {
			return $this->group_by;
		}
		
		/**
		 * Sets the GROUP BY specifications.
		 * @param array $groups Array of grouping expressions
		 * @return void
		 */
		public function setGroupBy(array $groups): void {
			$this->group_by = $groups;
		}
		
		/**
		 * Creates a deep clone of this retrieve operation.
		 * @return static A complete deep copy of this retrieve operation
		 */
		public function deepClone(): static {
			// Clone all child arrays with their AST nodes
			$clonedRanges = $this->cloneArray($this->ranges);
			$clonedValues = $this->cloneArray($this->values);
			$clonedMacros = $this->cloneArray($this->macros);
			$clonedSort = $this->cloneSortArray($this->sort);
			
			// Clone the conditions node if it exists
			$clonedConditions = $this->conditions?->deepClone();
			
			// Create new instance with cloned ranges
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->directives, $clonedRanges, $this->unique);
			
			// Set all the cloned properties
			$clone->values = $clonedValues;
			$clone->macros = $clonedMacros;
			$clone->conditions = $clonedConditions;
			$clone->sort = $clonedSort;
			
			// Copy primitive properties
			$clone->sort_in_application_logic = $this->sort_in_application_logic;
			$clone->window = $this->window;
			$clone->window_size = $this->window_size;
			
			// Establish parent relationships for all cloned children
			foreach ($clonedRanges as $range) {
				$range->setParent($clone);
			}
			
			foreach ($clonedValues as $value) {
				$value->setParent($clone);
			}
			
			foreach ($clonedMacros as $macro) {
				$macro->setParent($clone);
			}
			
			if ($clonedConditions) {
				$clonedConditions->setParent($clone);
			}
			
			foreach ($clonedSort as $sortItem) {
				$sortItem['ast']->setParent($clone);
			}
			
			return $clone;
		}
		
		/**
		 * Helper method to clone the sort array structure.
		 * @param array $sortArray The sort array to clone
		 * @return array A deep copy of the sort array
		 */
		protected function cloneSortArray(array $sortArray): array {
			$cloned = [];
			
			foreach ($sortArray as $key => $sortItem) {
				// Copy primitive values (direction, etc.)
				$newSortItem = $sortItem;
				
				// Clone the AST node
				$newSortItem['ast'] = $sortItem['ast']->deepClone();
				
				// Add cloned data to array
				$cloned[$key] = $newSortItem;
			}
			
			return $cloned;
		}
	}