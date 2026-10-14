<?php

	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	
	/**
	 * One lexical range scope: the root retrieve, or a where-clause any(...) quantifier.
	 * A range is bound in exactly one scope; inner scopes reference it as a correlation.
	 */
	class QuantifierScope {
		
		/** @var AstAny|null The quantifier that opens this scope, or null for the root retrieve */
		private ?AstAny $quantifier;
		
		/** @var QuantifierScope|null The enclosing scope, or null for the root retrieve */
		private ?QuantifierScope $parent;
		
		/** @var array<string, AstRange> Ranges bound in this scope, keyed by range name */
		private array $boundRanges;
		
		/** @var QuantifierScope[] Directly nested quantifier scopes, in source order */
		private array $children;
		
		/**
		 * QuantifierScope constructor
		 * @param AstAny|null $quantifier The quantifier that opens this scope, or null for the root retrieve
		 * @param QuantifierScope|null $parent The enclosing scope, or null for the root retrieve
		 */
		public function __construct(?AstAny $quantifier, ?QuantifierScope $parent) {
			$this->quantifier = $quantifier;
			$this->parent = $parent;
			$this->boundRanges = [];
			$this->children = [];
			
			if ($parent !== null) {
				$parent->children[] = $this;
			}
		}
		
		/**
		 * Returns the quantifier that opens this scope.
		 * @return AstAny|null Null for the root retrieve
		 */
		public function getQuantifier(): ?AstAny {
			return $this->quantifier;
		}
		
		/**
		 * Returns the directly nested quantifier scopes.
		 * @return QuantifierScope[]
		 */
		public function getChildren(): array {
			return $this->children;
		}
		
		/**
		 * Binds a range to this scope.
		 * @param AstRange $range
		 * @return void
		 */
		public function bind(AstRange $range): void {
			$this->boundRanges[$range->getName()] = $range;
		}
		
		/**
		 * Returns the ranges bound in this scope.
		 * @return array<string, AstRange> Keyed by range name
		 */
		public function getBoundRanges(): array {
			return $this->boundRanges;
		}
		
		/**
		 * Returns true if this scope is $scope or encloses it.
		 * @param QuantifierScope $scope
		 * @return bool
		 */
		public function enclosesOrIs(QuantifierScope $scope): bool {
			for ($current = $scope; $current !== null; $current = $current->parent) {
				if ($current === $this) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns a short description of this scope for error messages.
		 * @return string
		 */
		public function describe(): string {
			if ($this->quantifier === null) {
				return 'the retrieve statement';
			}
			
			$names = array_map(
				fn($identifier) => $identifier->getRange()?->getName() ?? '?',
				AstUtilities::collectIdentifiersFromAst($this->quantifier->getIdentifier())
			);
			
			return 'any(' . implode(', ', array_unique($names)) . ' ...)';
		}
	}
