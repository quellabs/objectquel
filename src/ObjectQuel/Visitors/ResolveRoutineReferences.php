<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\FindPropertyRange;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\RoutineScope;

	/**
	 * Types identifiers that name routine variables (RoutineVariable) or read a
	 * cursor row (CursorRoot/CursorField), and rejects references the routine's
	 * scope doesn't allow at this point. Range references are left for the
	 * regular query pipeline.
	 */
	class ResolveRoutineReferences extends FindPropertyRange implements AstVisitorInterface {

		private RoutineScope $scope;

		/** True inside retrieve/append/replace/delete, where ranges and unqualified properties resolve */
		private bool $inQueryStatement;

		/**
		 * @param EntityStore $entityStore Used to detect a variable name that is also an unqualified property
		 * @param RoutineScope $scope Names visible at the statement being visited
		 * @param bool $inQueryStatement True for embedded query statements, false for procedural expressions
		 */
		public function __construct(EntityStore $entityStore, RoutineScope $scope, bool $inQueryStatement) {
			parent::__construct($entityStore);
			$this->scope = $scope;
			$this->inQueryStatement = $inQueryStatement;
		}

		/**
		 * Classifies each root identifier against the routine scope.
		 * @param AstInterface $node The node being visited
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier || $node->getParent() instanceof AstIdentifier) {
				return;
			}

			$name = $node->getName();

			if ($this->scope->isRange($name)) {
				if (!$this->inQueryStatement) {
					throw new SemanticException("Range '{$name}' can only be used inside retrieve, append, replace or delete.");
				}

				return;
			}

			if ($this->scope->isScalar($name)) {
				$this->resolveVariable($node);
				return;
			}

			if ($this->scope->isCursor($name)) {
				$this->resolveCursorField($node);
				return;
			}

			if ($this->scope->isDeclaredAnywhere($name)) {
				throw new SemanticException("'{$name}' is used before its declaration.");
			}

			// Inside a query statement an unknown bare name may still be an
			// unqualified property or a target-list alias; the query pipeline decides.
			if (!$this->inQueryStatement) {
				throw new SemanticException("Undefined name '{$name}'.");
			}
		}

		/**
		 * Types a bare parameter/scalar reference, rejecting names the query pipeline would also resolve.
		 * @param AstIdentifier $node Root identifier naming a scalar
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function resolveVariable(AstIdentifier $node): void {
			$name = $node->getName();

			if ($node->getNext() !== null) {
				throw new SemanticException("'{$name}' is a scalar variable and has no fields; '{$node->getCompleteName()}' is invalid.");
			}

			if ($this->inQueryStatement) {
				// ResolveUnqualifiedProperty and ExpandMacros would otherwise rewrite
				// this node; reject the ambiguity instead of picking a winner.
				if (!empty($this->findRanges($name, $this->scope->getRanges()))) {
					throw new SemanticException("'{$name}' is both a routine variable and a property of a declared range. Rename the variable or qualify the property.");
				}

				if ($this->collidesWithTargetListName($node)) {
					throw new SemanticException("'{$name}' is both a routine variable and a target-list name in the same retrieve. Rename one of them.");
				}
			}

			$node->setType(IdentifierType::RoutineVariable);
		}

		/**
		 * Types `cursorName.field`, which is only valid inside that cursor's own `foreach`.
		 * @param AstIdentifier $node Root identifier naming a cursor
		 * @return void
		 * @throws SemanticException
		 */
		private function resolveCursorField(AstIdentifier $node): void {
			$cursorName = $node->getName();
			$field = $node->getNext();

			if ($field === null) {
				throw new SemanticException("Cursor '{$cursorName}' is not a value. Read its fields as '{$cursorName}.field' inside 'foreach {$cursorName}'.");
			}

			if ($field->getNext() !== null) {
				throw new SemanticException("'{$node->getCompleteName()}' is invalid: a cursor row field has no further fields.");
			}

			if (!$this->scope->isLoopOpen($cursorName)) {
				throw new SemanticException("'{$node->getCompleteName()}' can only be read inside 'foreach {$cursorName}'.");
			}

			if (!$this->scope->getCursorQuery($cursorName)->hasValueAlias($field->getName())) {
				throw new SemanticException("Cursor '{$cursorName}' has no field '{$field->getName()}'.");
			}

			$node->setType(IdentifierType::CursorRoot);
			$field->setType(IdentifierType::CursorField);
		}

		/**
		 * True when $node sits in a retrieve with a target-list entry of the same
		 * name that ExpandMacros would substitute for it.
		 * @param AstIdentifier $node Bare identifier
		 * @return bool
		 */
		private function collidesWithTargetListName(AstIdentifier $node): bool {
			$ancestor = $node->getParent();

			// Range join conditions are shared across statements, so their
			// parent chain doesn't lead to the statement being checked.
			while ($ancestor !== null && !$ancestor instanceof AstRetrieve) {
				if ($ancestor instanceof AstRange) {
					return false;
				}

				$ancestor = $ancestor->getParent();
			}

			$aliased = $ancestor?->getValueExpression($node->getName());

			// `retrieve (userId)` names its entry after the variable it reads, so
			// substituting it changes nothing
			if ($aliased === null || ($aliased instanceof AstIdentifier && $aliased->getCompleteName() === $node->getName())) {
				return false;
			}

			return true;
		}
	}
