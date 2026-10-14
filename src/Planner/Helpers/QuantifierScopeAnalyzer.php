<?php

	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateNoNestedAny;
	
	/**
	 * Builds the lexical range scopes of a retrieve: the root plus one scope per where-clause any(...).
	 *
	 * Scopes bind top-down. A range referenced by a scope, or on the via chain of a range it binds,
	 * is bound there unless an enclosing scope already binds it (then it is a correlation reference).
	 */
	class QuantifierScopeAnalyzer {
		
		/** @var array<string, AstRange> Ranges of the analyzed retrieve, keyed by name */
		private array $rangesByName = [];
		
		/** @var array<string, QuantifierScope> Scope that binds each range, keyed by range name */
		private array $bindings = [];
		
		/**
		 * Analyzes the retrieve and returns its root scope.
		 * @param AstRetrieve $ast
		 * @return QuantifierScope
		 * @throws SemanticException On a range ownership conflict or an unsupported nested any(...)
		 */
		public function analyze(AstRetrieve $ast): QuantifierScope {
			$this->rejectNestedAnyOutsideWhere($ast);
			
			$this->rangesByName = [];
			$this->bindings = [];
			
			foreach ($ast->getRanges() as $range) {
				$this->rangesByName[$range->getName()] = $range;
			}
			
			$root = new QuantifierScope(null, null);
			$quantifierScopes = $this->buildQuantifierScopes($ast, $root);
			
			// Root first, so every quantifier sees what the retrieve itself binds
			$this->bindReferences($root, $this->collectRootIdentifiers($ast));
			
			// Pre-order, so an enclosing quantifier binds before its nested ones
			foreach ($quantifierScopes as $scope) {
				$this->bindQuantifierScope($scope);
			}
			
			return $root;
		}
		
		/**
		 * Rejects nested any(...) in the projection, sort, group by and having clauses.
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws SemanticException
		 */
		private function rejectNestedAnyOutsideWhere(AstRetrieve $ast): void {
			foreach ($ast->getValues() as $value) {
				$value->accept(new ValidateNoNestedAny("a retrieve statement's projection"));
			}
			
			foreach ($ast->getSort() as $sortItem) {
				$sortItem['ast']->accept(new ValidateNoNestedAny("a retrieve statement's sort by clause"));
			}
			
			foreach ($ast->getGroupBy() as $expression) {
				$expression->accept(new ValidateNoNestedAny("a retrieve statement's group by clause"));
			}
			
			$ast->getHaving()?->accept(new ValidateNoNestedAny("a retrieve statement's having clause"));
		}
		
		/**
		 * Creates a scope for every any(...) in the where clause, nested by enclosing any(...).
		 * @param AstRetrieve $ast
		 * @param QuantifierScope $root
		 * @return QuantifierScope[] Quantifier scopes in pre-order
		 */
		private function buildQuantifierScopes(AstRetrieve $ast, QuantifierScope $root): array {
			$conditions = $ast->getConditions();
			
			if ($conditions === null) {
				return [];
			}
			
			/** @var CollectNodes<AstAny> $visitor */
			$visitor = new CollectNodes(AstAny::class);
			$conditions->accept($visitor);
			
			// Visitors run parent-first, so an enclosing any(...) is always created before its nested ones
			$scopesByQuantifier = [];
			
			foreach ($visitor->getCollectedNodes() as $quantifier) {
				$enclosing = $this->findEnclosingQuantifier($quantifier);
				$parent = $enclosing === null ? $root : $scopesByQuantifier[spl_object_id($enclosing)];
				$scopesByQuantifier[spl_object_id($quantifier)] = new QuantifierScope($quantifier, $parent);
			}
			
			return array_values($scopesByQuantifier);
		}
		
		/**
		 * Returns the identifiers the retrieve references outside its where-clause quantifiers.
		 * @param AstRetrieve $ast
		 * @return AstIdentifier[]
		 */
		private function collectRootIdentifiers(AstRetrieve $ast): array {
			$identifiers = [];
			
			foreach ($ast->getValues() as $value) {
				$identifiers = array_merge($identifiers, AstUtilities::collectIdentifiersFromAst($value));
			}
			
			foreach ($ast->getSort() as $sortItem) {
				$identifiers = array_merge($identifiers, AstUtilities::collectIdentifiersFromAst($sortItem['ast']));
			}
			
			foreach ($ast->getGroupBy() as $expression) {
				$identifiers = array_merge($identifiers, AstUtilities::collectIdentifiersFromAst($expression));
			}
			
			$identifiers = array_merge($identifiers, AstUtilities::collectIdentifiersFromAst($ast->getHaving()));
			
			foreach (AstUtilities::collectIdentifiersFromAst($ast->getConditions()) as $identifier) {
				if ($this->findEnclosingQuantifier($identifier) === null) {
					$identifiers[] = $identifier;
				}
			}
			
			return $identifiers;
		}
		
		/**
		 * Binds the ranges a quantifier references in its own expression and condition.
		 * @param QuantifierScope $scope
		 * @return void
		 * @throws SemanticException
		 */
		private function bindQuantifierScope(QuantifierScope $scope): void {
			// Only quantifier scopes are passed here
			$quantifier = $scope->getQuantifier() ?? throw new \LogicException('Root scope has no quantifier.');
			$sourceIdentifiers = AstUtilities::collectIdentifiersFromAst($quantifier->getIdentifier());
			
			// Quantifying over a row the enclosing scope already binds has two readings; require a separate range
			foreach ($sourceIdentifiers as $identifier) {
				$owner = $this->findBindingScope($identifier);
				
				if ($owner !== null && $owner->enclosesOrIs($scope)) {
					$rangeName = $this->rangeNameOf($identifier);
					
					throw new SemanticException(
						"{$scope->describe()} quantifies over range '{$rangeName}', which {$owner->describe()} already uses. " .
						"Declare a separate range (range of ... is ...) for the any(...) to quantify over."
					);
				}
			}
			
			$identifiers = $sourceIdentifiers;
			
			foreach (AstUtilities::collectIdentifiersFromAst($quantifier->getConditions()) as $identifier) {
				if ($this->findEnclosingQuantifier($identifier) === $quantifier) {
					$identifiers[] = $identifier;
				}
			}
			
			$this->bindReferences($scope, $identifiers);
		}
		
		/**
		 * Binds the ranges of the given identifiers to the scope.
		 * @param QuantifierScope $scope
		 * @param AstIdentifier[] $identifiers
		 * @return void
		 * @throws SemanticException
		 */
		private function bindReferences(QuantifierScope $scope, array $identifiers): void {
			foreach ($identifiers as $identifier) {
				$range = $this->rangesByName[$this->rangeNameOf($identifier)] ?? null;
				
				if ($range !== null) {
					$this->bindRange($scope, $range);
				}
			}
		}
		
		/**
		 * Binds a range and the unbound ranges on its via chain to the scope.
		 * @param QuantifierScope $scope
		 * @param AstRange $range
		 * @return void
		 * @throws SemanticException
		 */
		private function bindRange(QuantifierScope $scope, AstRange $range): void {
			$owner = $this->bindings[$range->getName()] ?? null;
			
			// Already bound here, or a correlation reference to an enclosing scope's row
			if ($owner !== null && $owner->enclosesOrIs($scope)) {
				return;
			}
			
			if ($owner !== null) {
				throw new SemanticException(
					"Range '{$range->getName()}' is used by both {$owner->describe()} and {$scope->describe()}. " .
					"A range can belong to only one any(...); declare a separate range (range of ... is ...) for each."
				);
			}
			
			if ($scope->getQuantifier() !== null && !$range instanceof AstRangeDatabase) {
				throw new SemanticException(
					"Range '{$range->getName()}' cannot be used inside any(...) in a where clause; only entity ranges are supported there."
				);
			}
			
			$scope->bind($range);
			$this->bindings[$range->getName()] = $scope;
			
			foreach ($this->getViaParents($range) as $parent) {
				$this->bindRange($scope, $parent);
			}
		}
		
		/**
		 * Returns the ranges a range's via (join) condition depends on, excluding itself.
		 * @param AstRange $range
		 * @return AstRange[]
		 */
		private function getViaParents(AstRange $range): array {
			$parents = [];
			
			foreach (AstUtilities::collectIdentifiersFromAst($range->getJoinProperty()) as $identifier) {
				$name = $this->rangeNameOf($identifier);
				
				if ($name !== $range->getName() && isset($this->rangesByName[$name])) {
					$parents[$name] = $this->rangesByName[$name];
				}
			}
			
			return array_values($parents);
		}
		
		/**
		 * Returns the scope that binds the identifier's range, or null when it is unbound.
		 * @param AstIdentifier $identifier
		 * @return QuantifierScope|null
		 */
		private function findBindingScope(AstIdentifier $identifier): ?QuantifierScope {
			return $this->bindings[$this->rangeNameOf($identifier)] ?? null;
		}
		
		/**
		 * Returns the nearest any(...) enclosing the node, or null when there is none.
		 * @param AstInterface $node
		 * @return AstAny|null
		 */
		private function findEnclosingQuantifier(AstInterface $node): ?AstAny {
			for ($current = $node->getParent(); $current !== null; $current = $current->getParent()) {
				if ($current instanceof AstAny) {
					return $current;
				}
				
				if ($current instanceof AstRetrieve) {
					return null;
				}
			}
			
			return null;
		}
		
		/**
		 * Returns the range name of an identifier collected by CollectIdentifiers.
		 * @param AstIdentifier $identifier
		 * @return string
		 */
		private function rangeNameOf(AstIdentifier $identifier): string {
			// CollectIdentifiers only returns identifiers with a range
			return $identifier->getRange()?->getName() ?? throw new \LogicException('Identifier has no range.');
		}
	}
