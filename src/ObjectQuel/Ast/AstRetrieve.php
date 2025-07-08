<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsJsonIdentifier;
	
	/**
	 * Class AstRetrieve
	 *
	 * Represents a retrieve operation in the AST (Abstract Syntax Tree).
	 */
	class AstRetrieve extends Ast {
		
		protected array $directives;
		protected array $values;
		protected array $macros;
		protected array $ranges;
		protected ?AstInterface $conditions;
		protected array $sort;
		protected bool $sort_in_application_logic;
		protected bool $unique;
		protected ?int $window;
		protected ?int $window_size;
		
		/**
		 * AstRetrieve constructor.
		 * Initializes an empty array of values and sets conditions to null.
		 * @param AstRangeDatabase[] $ranges
		 * @param bool $unique True if the results are unique (DISTINCT), false if not
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
		}
		
		/**
		 * Accepteert een bezoeker (visitor) om bewerkingen uit te voeren op deze node.
		 * Deze methode laat de visitor eerst bewerkingen uitvoeren op de node zelf en
		 * vervolgens op elk van de ranges die bij deze node horen.
		 * @param AstVisitorInterface $visitor De visitor die geaccepteerd wordt.
         * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			// Laat de visitor bewerkingen uitvoeren op elk range-object in de node.
			foreach($this->ranges as $value) {
				$value->accept($visitor);
			}
			
			// Laat de visitor bewerkingen uitvoeren op de node, exclusief de ranges.
			$this->acceptWithoutRanges($visitor);
		}
		
		/**
		 * Accepteert een bezoeker om bewerkingen uit te voeren op deze node, maar
		 * zonder de ranges te betrekken. Dit is nuttig wanneer bewerkingen op alleen
		 * de node zelf vereist zijn, zonder de ranges te beïnvloeden.
		 * @param AstVisitorInterface $visitor De visitor die geaccepteerd wordt.
         * @return void
		 */
		public function acceptWithoutRanges(AstVisitorInterface $visitor): void {
			// Laat de parent class de visitor accepteren en bewerkingen uitvoeren.
			parent::accept($visitor);
			
			// Laat de visitor bewerkingen uitvoeren op elk waarde-object in de node.
			foreach($this->values as $value) {
				$value->accept($visitor);
			}

			// Als er condities zijn, laat deze ook door de visitor bewerkt worden.
			if ($this->conditions !== null) {
				$this->conditions->accept($visitor);
			}
			
			// Als er een sort clausule is, laat deze ook door de visitor bewerkt worden.
            foreach($this->sort as $s) {
				$s['ast']->accept($visitor);
			}

			// En ook de macros
			foreach($this->macros as $macro) {
				$macro->accept($visitor);
			}
		}
		
		/**
		 * Add a value to be retrieved.
		 * @param AstInterface $ast The value to add.
		 */
		public function addValue(AstInterface $ast): void {
			$this->values[] = $ast;
		}
		
		/**
		 * Get the values to be retrieved.
		 * @return AstAlias[] The array of values.
		 */
		public function getValues(): array {
			return $this->values;
		}
		
		/**
		 * Replaces the values with something else
		 * @param array $values
		 * @return void
		 */
		public function setValues(array $values): void {
			$this->values = $values;
		}
		
		/**
		 * Get the conditions for this retrieve operation, if any.
		 * @return AstInterface|null The conditions or null.
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Set the conditions for this retrieve operation.
		 *
		 * @param AstInterface|null $ast The conditions.
		 */
		public function setConditions(?AstInterface $ast): void {
			$this->conditions = $ast;
		}
		
		/**
		 * Checks if a condition node involves a specific range.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		protected function doesConditionInvolveRange(AstInterface $condition, AstRange $range): bool {
			// For property access, check if the base entity matches our range
			if ($condition instanceof AstIdentifier) {
				return $condition->getRange()->getName() === $range->getName();
			}
			
			// For unary operations (NOT, etc.)
			if ($condition instanceof AstUnaryOperation) {
				return $this->doesConditionInvolveRange($condition->getExpression(), $range);
			}
			
			// For comparison operations, check each side
			if (
				$condition instanceof AstExpression ||
				$condition instanceof AstBinaryOperator ||
				$condition instanceof AstTerm ||
				$condition instanceof AstFactor
			) {
				$leftInvolves = $this->doesConditionInvolveRange($condition->getLeft(), $range);
				$rightInvolves = $this->doesConditionInvolveRange($condition->getRight(), $range);
				return $leftInvolves || $rightInvolves;
			}
			
			return false;
		}
		

		
		/**
		 * Returns database ranges
		 * @return array
		 */
		public function getOtherRanges(): array {
			return array_filter($this->ranges, function($range) {
				return !$range instanceof AstRangeDatabase;
			});
		}
		
		/**
		 * Returns the ranges used in the retrieve statement
		 * @return array
		 */
		public function getRanges(): array {
			return $this->ranges;
		}
		
		/**
		 * Sets a new set of ranges
		 * @return void
		 */
		public function setRanges(array $ranges): void {
			$this->ranges = $ranges;
		}
		
		/**
		 * Adds a new range to the range list
		 * @return void
		 */
		public function addRange(AstRangeDatabase $range): void {
			$this->ranges[] = $range;
		}
		
		/**
		 * Returns true if the query is unique
		 * @return bool
		 */
		public function isUnique(): bool {
			return $this->unique;
		}
		
		/**
		 * Returns all defined macros
		 * @return array
		 */
		public function getMacros(): array {
			return $this->macros;
		}
		
		/**
		 * Adds a new macro
		 * @param string $name
		 * @param AstInterface|null $ast
		 * @return void
		 */
		public function addMacro(string $name, ?AstInterface $ast): void {
			$this->macros[$name] = $ast;
		}
		
		/**
		 * Returns true if the macro exists, false if not
		 * @param string $name
		 * @return bool
		 */
		public function macroExists(string $name): bool {
			return isset($this->macros[$name]);
		}
        
        /**
         * Sets sort clause
         * @param array $sortArray
         * @return void
         */
		public function setSort(array $sortArray): void {
			$this->sort = $sortArray;
		}
		
		/**
		 * Returns the sort clause
		 * @return array|AstInterface[]
         */
		public function getSort(): array {
			return $this->sort;
		}
		
		/**
		 * Sets limit clause
		 * @param int $window
		 * @return void
		 */
		public function setWindow(int $window): void {
			$this->window = $window;
		}
		
		/**
		 * Returns the limit clause
		 * @return int|null
		 */
		public function getWindow(): ?int {
			return $this->window;
		}
		
		/**
		 * Sets limit clause
		 * @param int $windowSize
		 * @return void
		 */
		public function setWindowSize(int $windowSize): void {
			$this->window_size = $windowSize;
		}
		
		/**
		 * Returns the limit clause
		 * @return int|null
		 */
		public function getWindowSize(): ?int {
			return $this->window_size;
		}
		
		/**
		 * Sets unique flag
		 * @param bool $unique
		 * @return void
		 */
		public function setUnique(bool $unique): void {
			$this->unique = $unique;
		}
		
		/**
		 * Returns unique flag
		 * @return bool
		 */
		public function getUnique(): bool {
			return $this->unique;
		}
		
		/**
		 * Retourneert compiler directives voor deze query
		 * @return array
		 */
		public function getDirectives(): array {
			return $this->directives;
		}
		
		/**
		 * Returns a certain directive
		 * @param string $name
		 * @return mixed
		 */
		public function getDirective(string $name): mixed {
			return $this->directives[$name] ?? null;
		}
		
		/**
		 * Returns true if sorting (and pagination) should be done in the application logic
		 * Instead of in MYSQL. This will be the case if 'sort by' contains a method call.
		 * @return bool
		 */
		public function getSortInApplicationLogic(): bool {
			return $this->sort_in_application_logic;
		}
		
		/**
		 * Checks if the sort criteria contains a JSON identifier.
		 * @return bool True if sort contains a JSON identifier or if an exception is thrown during processing, false if sort is empty
		 */
		public function sortContainsJsonIdentifier(): bool {
			// Return false if the sort array is empty
			if (empty($this->sort)) {
				return false;
			}
			
			// Create a visitor object that will check for JSON identifiers
			$visitor = new ContainsJsonIdentifier();
			
			try {
				// Iterate through each value in the sort array
				// and apply the visitor pattern by calling accept() on each value
				foreach ($this->sort as $value) {
					$value["ast"]->accept($visitor);
				}
			} catch (\Exception $e) {
				// If an exception occurs during processing,
				// assume there is a JSON identifier and return true
				return true;
			}
			
			// If no exception was thrown and the sort was not empty,
			// return false (indicating it contains no JSON identifier)
			return false;
		}

		/**
		 * Sets the sort_in_application_logic flag
		 * @return void
		 */
		public function setSortInApplicationLogic(bool $setSort): void {
			$this->sort_in_application_logic = $setSort;
		}
	}