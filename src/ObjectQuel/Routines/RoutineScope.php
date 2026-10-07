<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineBlock;

	/**
	 * A routine's names: block-scoped locals and cursors (a stack of frames,
	 * pushed/popped around if/while/foreach/atomic bodies; the routine's
	 * own top-level body is the never-popped root frame), plus range aliases
	 * and parameters, which stay one flat, always-visible namespace since
	 * `range of` remains top-level-only. Declaring a name returns the name
	 * downstream code must use from here on: the name itself, unless it was
	 * already used by another declaration, in which case a
	 * fresh `name_2`, `name_3`, ... is minted so the routine's single flat SQL
	 * `DECLARE` section stays collision-free. Also tracks which `foreach`
	 * loops are currently open.
	 */
	class RoutineScope {

		/** @var array<int, array{scalars: array<string, string>, cursors: array<string, string>}> Scope stack, root frame first, current frame last */
		private array $frames;

		/** @var array<string, AstDeclare> Resolved cursor name => its declaration */
		private array $cursors = [];

		/** @var array<string, string> Lowercased resolved name => resolved name as declared */
		private array $variableNamesIgnoringCase = [];

		/** @var array<string, true> Lowercased resolved name => true, for every resolved scalar/cursor/range name handed out so far */
		private array $allResolvedNamesLower = [];

		/** @var array<string, AstRange> Range alias => range */
		private array $ranges = [];

		/**
		 * @var array<string, string> Entity-row parameter name => fully qualified entity class. Flat and
		 * root-scoped like $ranges: an entity-row binding only ever comes from a `trigger` routine's own
		 * parameter list, never from a block-local declaration, so there is no shadowing dimension to it.
		 */
		private array $entityRows = [];

		/** @var array<string, true> Every name the routine declares anywhere, for "used before declaration" errors */
		private array $allDeclaredNames;

		/** @var string[] Resolved cursor names of the enclosing `foreach` loops, outermost first */
		private array $openLoops = [];

		/** @var array<int, array{name: string, cursor: string}> Active `foreach ... as` row bindings, outermost first */
		private array $rowBindings = [];

		/**
		 * Initializes scope tracking with all names declared by the routine.
		 * @param string[] $allDeclaredNames Every name the routine declares, in any position
		 */
		public function __construct(array $allDeclaredNames) {
			$this->allDeclaredNames = array_fill_keys($allDeclaredNames, true);
			$this->frames = [['scalars' => [], 'cursors' => []]];
		}

		/**
		 * Opens a new block scope, entering an if/elseif/else, while, foreach or atomic body.
		 * @return void
		 */
		public function pushScope(): void {
			$this->frames[] = ['scalars' => [], 'cursors' => []];
		}

		/**
		 * Closes the innermost block scope, leaving its locals and cursors out of view.
		 * @return void
		 */
		public function popScope(): void {
			array_pop($this->frames);
		}

		/**
		 * Adds a parameter or scalar local to the current scope.
		 * @param string $name Variable name as written
		 * @return string The resolved name to use from here on; equal to $name unless it was used before
		 * @throws SemanticException When the name is reserved, a range, or already declared in this block
		 */
		public function declareScalar(string $name): string {
			$resolved = $this->resolveNewName($name);
			$this->frames[count($this->frames) - 1]['scalars'][$name] = $resolved;
			return $resolved;
		}

		/**
		 * Adds a `cursor` local to the current scope.
		 * @param AstDeclare $declaration The cursor's declaration; its initializer is an AstRetrieve
		 * @return string The resolved name to use from here on; equal to the declared name unless it was used before
		 * @throws SemanticException When the name is reserved, a range, or already declared in this block
		 */
		public function declareCursor(AstDeclare $declaration): string {
			$resolved = $this->resolveNewName($declaration->getName());
			$this->frames[count($this->frames) - 1]['cursors'][$declaration->getName()] = $resolved;
			$this->cursors[$resolved] = $declaration;
			return $resolved;
		}

		/**
		 * Rebinds an already-declared cursor to a new query, from this point in the current
		 * block onward. Mints a fresh resolved name for it, since a cursor's underlying SQL
		 * object is fixed to one query for its lifetime; an outer scope's binding reappears
		 * once the current block ends, same as any other name shadowed in a nested block.
		 * @param string $name Cursor name as written at the assignment
		 * @param AstRetrieve $query The new query
		 * @return string The resolved name to use from here on
		 */
		public function rebindCursor(string $name, AstRetrieve $query): string {
			$resolved = $this->mintUnusedName($name);
			$this->allResolvedNamesLower[strtolower($resolved)] = true;
			$this->declareVariableName($resolved);

			$declaration = new AstDeclare($name, 'cursor', $query);
			$this->frames[count($this->frames) - 1]['cursors'][$name] = $resolved;
			$this->cursors[$resolved] = $declaration;

			return $resolved;
		}

		/**
		 * Adds a `range of` alias. Ranges are always root-scoped and never reused, so their name never changes.
		 * @param AstRange $range The declared range
		 * @return void
		 * @throws SemanticException When the name is reserved or already declared
		 */
		public function declareRange(AstRange $range): void {
			$name = $range->getName();
			$this->assertNotKeyword($name);

			if ($this->isVisibleInChain($name)) {
				throw new SemanticException("'{$name}' is already declared in this scope.");
			}

			$this->ranges[$name] = $range;
			$this->allResolvedNamesLower[strtolower($name)] = true;
		}

		/**
		 * Adds an entity-row parameter, e.g. `UserEntity old` in a `trigger`-returning routine's parameter list.
		 * @param string $name Parameter name as written
		 * @param string $entityClass Fully qualified entity class the parameter is typed with
		 * @return void
		 * @throws SemanticException When the name is reserved or already declared
		 */
		public function declareEntityRow(string $name, string $entityClass): void {
			$this->assertNotKeyword($name);

			if ($this->isVisibleInChain($name)) {
				throw new SemanticException("'{$name}' is already declared in this scope.");
			}

			$this->entityRows[$name] = $entityClass;
			$this->allResolvedNamesLower[strtolower($name)] = true;
			$this->declareVariableName($name);
		}

		/**
		 * Reports whether a name is a declared entity-row parameter.
		 * @param string $name Name to look up
		 * @return bool True when $name is an entity-row parameter
		 */
		public function isEntityRow(string $name): bool {
			return isset($this->entityRows[$name]);
		}

		/**
		 * Returns the entity class an entity-row parameter is typed with.
		 * @param string $name Entity-row parameter name, which must be declared (see isEntityRow())
		 * @return string Fully qualified entity class
		 */
		public function getEntityRowClass(string $name): string {
			return $this->entityRows[$name];
		}

		/**
		 * Reports whether a name is a declared scalar variable visible from the current scope.
		 * @param string $name Name to look up
		 * @return bool True when $name is a parameter or scalar local visible here
		 */
		public function isScalar(string $name): bool {
			return $this->findInChain('scalars', $name) !== null;
		}

		/**
		 * Reports whether a name is a declared cursor visible from the current scope.
		 * @param string $name Name to look up
		 * @return bool True when $name is a cursor local visible here
		 */
		public function isCursor(string $name): bool {
			return $this->findInChain('cursors', $name) !== null;
		}

		/**
		 * Reports whether a name is a declared range.
		 * @param string $name Name to look up
		 * @return bool True when $name is a range alias declared so far
		 */
		public function isRange(string $name): bool {
			return isset($this->ranges[$name]);
		}

		/**
		 * Reports whether a name is declared anywhere in the routine, at any depth.
		 * @param string $name Name to look up
		 * @return bool True when the routine declares $name somewhere, whether or not that point has been reached
		 */
		public function isDeclaredAnywhere(string $name): bool {
			return isset($this->allDeclaredNames[$name]);
		}

		/**
		 * Resolves a scalar reference to the name downstream code should use, from the current scope outward.
		 * @param string $name Name as written at the reference
		 * @return string|null The visible declaration's resolved name, or null when none is visible
		 */
		public function resolveScalar(string $name): ?string {
			return $this->findInChain('scalars', $name);
		}

		/**
		 * Resolves a cursor reference to the name downstream code should use, from the current scope outward.
		 * @param string $name Name as written at the reference
		 * @return string|null The visible declaration's resolved name, or null when none is visible
		 */
		public function resolveCursor(string $name): ?string {
			return $this->findInChain('cursors', $name);
		}

		/**
		 * Returns the query a cursor local was declared with.
		 * @param string $name Resolved cursor name, which must be declared
		 * @return AstRetrieve
		 */
		public function getCursorQuery(string $name): AstRetrieve {
			/** @var AstRetrieve $query Checked when the cursor was declared */
			$query = $this->cursors[$name]->getInitializer();
			return $query;
		}

		/**
		 * Returns the ranges declared in the routine scope.
		 * @return AstRange[] Ranges declared so far, in source order
		 */
		public function getRanges(): array {
			return array_values($this->ranges);
		}

		/**
		 * Marks a `foreach` over $cursorName (resolved) as open.
		 * @param string $cursorName Resolved name of the cursor being looped
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
		 * Reports whether a cursor loop is currently open.
		 * @param string $cursorName Resolved cursor name
		 * @return bool True when a `foreach` over $cursorName encloses the current statement
		 */
		public function isLoopOpen(string $cursorName): bool {
			return in_array($cursorName, $this->openLoops, true);
		}

		/**
		 * Binds a `foreach (cursorName as rowName)` loop's row name for its body, checked against
		 * whatever else is in scope right now. The binding is popped when the loop's body
		 * finishes (see unbindRow()), so an outer binding is restored after a nested loop.
		 * @param string $rowName Name the loop binds to its current row
		 * @param string $cursorName Resolved name of the cursor the row is read from
		 * @return void
		 * @throws SemanticException When $rowName collides with something already visible
		 */
		public function bindRow(string $rowName, string $cursorName): void {
			$this->assertNotKeyword($rowName);

			if ($this->isVisibleInChain($rowName)) {
				throw new SemanticException("'{$rowName}' is already in use in this routine and can't be reused as 'foreach ({$cursorName} as {$rowName})'.");
			}

			$this->rowBindings[] = ['name' => $rowName, 'cursor' => $cursorName];
		}

		/**
		 * Unbinds the innermost `foreach ... as` row name, once its body has been checked.
		 * @return void
		 */
		public function unbindRow(): void {
			array_pop($this->rowBindings);
		}

		/**
		 * Reports whether a name is a currently open `foreach ... as` row binding.
		 * @param string $name Name to look up
		 * @return bool True when $name is bound by an enclosing `foreach ... as`
		 */
		public function isRowBinding(string $name): bool {
			foreach ($this->rowBindings as $binding) {
				if ($binding['name'] === $name) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Returns the cursor a row binding reads from.
		 * @param string $rowName Row binding name, which must be currently open (see isRowBinding())
		 * @return string Resolved cursor name
		 */
		public function getRowCursor(string $rowName): string {
			for ($i = count($this->rowBindings) - 1; $i >= 0; $i--) {
				if ($this->rowBindings[$i]['name'] === $rowName) {
					return $this->rowBindings[$i]['cursor'];
				}
			}

			throw new \LogicException("'{$rowName}' is not an open row binding; isRowBinding() should have been checked first.");
		}

		/**
		 * Computes the resolved name for a new scalar/cursor declaration, minting a fresh
		 * `name_2`, `name_3`, ... when another block has already used the name.
		 * @param string $name Name as written at the declaration
		 * @return string The resolved name
		 * @throws SemanticException
		 */
		private function resolveNewName(string $name): string {
			$this->assertNotKeyword($name);

			if (isset($this->ranges[$name]) || isset($this->entityRows[$name]) || $this->isDeclaredInCurrentFrame($name)) {
				throw new SemanticException("'{$name}' is already declared in this scope.");
			}

			$priorName = $this->variableNamesIgnoringCase[strtolower($name)] ?? null;
			if ($priorName !== null && $priorName !== $name) {
				throw new SemanticException("'{$priorName}' and '{$name}' differ only in case; rename one.");
			}

			$resolved = isset($this->allResolvedNamesLower[strtolower($name)]) ? $this->mintUnusedName($name) : $name;
			$this->allResolvedNamesLower[strtolower($resolved)] = true;
			$this->declareVariableName($resolved);

			return $resolved;
		}

		/**
		 * Checks whether this block already declares a scalar or cursor with the name.
		 * @param string $name Name as written at the declaration
		 * @return bool
		 */
		private function isDeclaredInCurrentFrame(string $name): bool {
			$frame = $this->frames[count($this->frames) - 1];
			return isset($frame['scalars'][$name]) || isset($frame['cursors'][$name]);
		}

		/**
		 * Generates the first `name_2`, `name_3`, ... not already handed out to any local, cursor, range or parameter.
		 * @param string $name Base name being reused
		 * @return string An unused resolved name
		 */
		private function mintUnusedName(string $name): string {
			for ($suffix = 2;; $suffix++) {
				$candidate = "{$name}_{$suffix}";

				if (!isset($this->allResolvedNamesLower[strtolower($candidate)])) {
					return $candidate;
				}
			}
		}

		/**
		 * Reports whether a name is visible anywhere in the current scope chain: the root-level ranges,
		 * or a scalar/cursor declared in the current frame or an enclosing one.
		 * @param string $name Name to look up
		 * @return bool
		 */
		private function isVisibleInChain(string $name): bool {
			if (isset($this->ranges[$name]) || isset($this->entityRows[$name])) {
				return true;
			}

			return $this->findInChain('scalars', $name) !== null || $this->findInChain('cursors', $name) !== null;
		}

		/**
		 * Looks up a name from the innermost frame outward, stopping when the other kind shadows it.
		 * @param 'scalars'|'cursors' $kind Which per-frame map to search
		 * @param string $name Name to look up
		 * @return string|null The resolved name of the innermost visible declaration, or null when none is visible
		 */
		private function findInChain(string $kind, string $name): ?string {
			$otherKind = $kind === 'scalars' ? 'cursors' : 'scalars';
			for ($i = count($this->frames) - 1; $i >= 0; $i--) {
				if (isset($this->frames[$i][$kind][$name])) {
					return $this->frames[$i][$kind][$name];
				}
				if (isset($this->frames[$i][$otherKind][$name])) {
					return null;
				}
			}

			return null;
		}

		/**
		 * Rejects a statement keyword used as a name.
		 * @param string $name Name about to be declared
		 * @return void
		 * @throws SemanticException
		 */
		private function assertNotKeyword(string $name): void {
			if (in_array(strtolower($name), RoutineBlock::STATEMENT_KEYWORDS, true)) {
				throw new SemanticException("'{$name}' is a statement keyword and can't be used as a name.");
			}
		}

		/**
		 * Records a resolved variable or cursor name, rejecting one that differs only in case from an earlier one.
		 * @param string $name Resolved name being declared
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
