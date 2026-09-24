<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineBlock;

	/**
	 * The single flat namespace of a routine: parameters, locals and range
	 * aliases, filled in source order so declare-before-use falls out of it.
	 * Also tracks which `foreach` loops are currently open.
	 */
	class RoutineScope {

		/** @var array<string, true> Parameter and scalar local names */
		private array $scalars = [];

		/** @var array<string, AstDeclare> Cursor name => its declaration */
		private array $cursors = [];

		/** @var array<string, string> Lowercased variable and cursor name => name as declared */
		private array $variableNamesIgnoringCase = [];

		/** @var array<string, AstRange> Range alias => range */
		private array $ranges = [];

		/** @var array<string, true> Every name the routine declares anywhere, for "used before declaration" errors */
		private array $allDeclaredNames;

		/** @var string[] Cursor names of the enclosing `foreach` loops, outermost first */
		private array $openLoops = [];

		/**
		 * @param string[] $allDeclaredNames Every name the routine declares, in any position
		 */
		public function __construct(array $allDeclaredNames) {
			$this->allDeclaredNames = array_fill_keys($allDeclaredNames, true);
		}

		/**
		 * Adds a parameter or scalar local.
		 * @param string $name Variable name
		 * @return void
		 * @throws SemanticException When the name is reserved or already declared
		 */
		public function declareScalar(string $name): void {
			$this->assertNameAvailable($name);
			$this->declareVariableName($name);
			$this->scalars[$name] = true;
		}

		/**
		 * Adds a `cursor` local.
		 * @param AstDeclare $declaration The cursor's declaration; its initializer is an AstRetrieve
		 * @return void
		 * @throws SemanticException When the name is reserved or already declared
		 */
		public function declareCursor(AstDeclare $declaration): void {
			$this->assertNameAvailable($declaration->getName());
			$this->declareVariableName($declaration->getName());
			$this->cursors[$declaration->getName()] = $declaration;
		}

		/**
		 * Adds a `range of` alias.
		 * @param AstRange $range The declared range
		 * @return void
		 * @throws SemanticException When the name is reserved or already declared
		 */
		public function declareRange(AstRange $range): void {
			$this->assertNameAvailable($range->getName());
			$this->ranges[$range->getName()] = $range;
		}

		/**
		 * @param string $name Name to look up
		 * @return bool True when $name is a parameter or scalar local declared so far
		 */
		public function isScalar(string $name): bool {
			return isset($this->scalars[$name]);
		}

		/**
		 * @param string $name Name to look up
		 * @return bool True when $name is a cursor local declared so far
		 */
		public function isCursor(string $name): bool {
			return isset($this->cursors[$name]);
		}

		/**
		 * @param string $name Name to look up
		 * @return bool True when $name is a range alias declared so far
		 */
		public function isRange(string $name): bool {
			return isset($this->ranges[$name]);
		}

		/**
		 * @param string $name Name to look up
		 * @return bool True when the routine declares $name somewhere, whether or not that point has been reached
		 */
		public function isDeclaredAnywhere(string $name): bool {
			return isset($this->allDeclaredNames[$name]);
		}

		/**
		 * Returns the query a cursor local was declared with.
		 * @param string $name Cursor name, which must be declared
		 * @return AstRetrieve
		 */
		public function getCursorQuery(string $name): AstRetrieve {
			/** @var AstRetrieve $query Checked when the cursor was declared */
			$query = $this->cursors[$name]->getInitializer();
			return $query;
		}

		/**
		 * @return AstRange[] Ranges declared so far, in source order
		 */
		public function getRanges(): array {
			return array_values($this->ranges);
		}

		/**
		 * Marks a `foreach` over $cursorName as open.
		 * @param string $cursorName Cursor being looped
		 * @return void
		 */
		public function openLoop(string $cursorName): void {
			$this->openLoops[] = $cursorName;
		}

		/**
		 * Marks the innermost `foreach` as closed.
		 * @return void
		 */
		public function closeLoop(): void {
			array_pop($this->openLoops);
		}

		/**
		 * @param string $cursorName Cursor name
		 * @return bool True when a `foreach` over $cursorName encloses the current statement
		 */
		public function isLoopOpen(string $cursorName): bool {
			return in_array($cursorName, $this->openLoops, true);
		}

		/**
		 * Rejects statement keywords and names already in the routine's one namespace.
		 * @param string $name Name about to be declared
		 * @return void
		 * @throws SemanticException
		 */
		private function assertNameAvailable(string $name): void {
			// Statement keywords are matched before declarations/assignments, so a
			// variable with such a name could never be assigned or read back.
			if (in_array(strtolower($name), RoutineBlock::STATEMENT_KEYWORDS, true)) {
				throw new SemanticException("'{$name}' is a statement keyword and can't be used as a name.");
			}

			if (isset($this->scalars[$name]) || isset($this->cursors[$name]) || isset($this->ranges[$name])) {
				throw new SemanticException("'{$name}' is already declared in this routine. Routines have one flat scope; every name can be declared once.");
			}
		}

		/**
		 * Records a variable or cursor name, rejecting one that differs only in case from an earlier one.
		 * @param string $name Name being declared
		 * @return void
		 * @throws SemanticException
		 */
		private function declareVariableName(string $name): void {
			$key = strtolower($name);

			// Rejected on every engine, since MySQL/MariaDB and SQL Server can't tell them apart
			if (isset($this->variableNamesIgnoringCase[$key])) {
				throw new SemanticException("'{$this->variableNamesIgnoringCase[$key]}' and '{$name}' differ only in case; rename one.");
			}

			$this->variableNamesIgnoringCase[$key] = $name;
		}
	}
