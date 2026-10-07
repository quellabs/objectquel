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
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\PropertyRangeFinder;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineScope;

	/**
	 * Types identifiers that name routine variables (RoutineVariable) or read a
	 * cursor row through its `foreach ... as` binding (CursorRoot/CursorField),
	 * and rejects references the routine's scope doesn't allow at this point.
	 * A resolved row-binding identifier is rewritten in place to the cursor's
	 * own name, so downstream compilation sees exactly what it already
	 * expected before `foreach` required a separate row name. Range references
	 * are left for the regular query pipeline.
	 */
	class ResolveRoutineReferences extends PropertyRangeFinder implements AstVisitorInterface {

		private EntityStore $entityStore;
		private RoutineScope $scope;

		/** True inside retrieve/append/replace/delete, where ranges and unqualified properties resolve */
		private bool $inQueryStatement;

		/**
		 * @param EntityStore $entityStore Used to detect a variable name that is also an unqualified property,
		 *        and to resolve entity-row parameter fields against mapped columns
		 * @param RoutineScope $scope Names visible at the statement being visited
		 * @param bool $inQueryStatement True for embedded query statements, false for procedural expressions
		 */
		public function __construct(EntityStore $entityStore, RoutineScope $scope, bool $inQueryStatement) {
			parent::__construct($entityStore);
			$this->entityStore = $entityStore;
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

			if ($this->scope->isRowBinding($name)) {
				$this->resolveRowField($node);
				return;
			}

			if ($this->scope->isEntityRow($name)) {
				$this->resolveEntityRowField($node);
				return;
			}

			if ($this->scope->isCursor($name)) {
				throw new SemanticException("Cursor '{$name}' is not a value; read its fields through its 'foreach ({$name} as row)' binding.");
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
				// UnqualifiedPropertyResolver and ExpandMacros would otherwise rewrite
				// this node; reject the ambiguity instead of picking a winner.
				if (!empty($this->findRanges($name, $this->scope->getRanges()))) {
					throw new SemanticException("'{$name}' is both a routine variable and a property of a declared range. Rename the variable or qualify the property.");
				}

				if ($this->collidesWithTargetListName($node)) {
					throw new SemanticException("'{$name}' is both a routine variable and a target-list name in the same retrieve. Rename one of them.");
				}
			}

			// isScalar($name) was already confirmed by visitNode(), so resolveScalar() can't return null here
			$node->setName($this->scope->resolveScalar($name) ?? $name);
			$node->setType(IdentifierType::RoutineVariable);
		}

		/**
		 * Types `rowName.field`, reading the row bound by an enclosing `foreach cursor as rowName`.
		 * Rewrites $node's name in place to the underlying cursor's own name, so every later pass
		 * (RoutineFieldTypes, RoutineReferenceSql, the dialect lowerings) sees the same identifier
		 * shape it already handled before a loop's row got its own name.
		 * @param AstIdentifier $node Root identifier naming a row binding
		 * @return void
		 * @throws SemanticException
		 */
		private function resolveRowField(AstIdentifier $node): void {
			$rowName = $node->getName();
			$field = $node->getNext();

			if ($field === null) {
				throw new SemanticException("'{$rowName}' is not a value. Read its fields as '{$rowName}.field'.");
			}

			if ($field->getNext() !== null) {
				throw new SemanticException("'{$node->getCompleteName()}' is invalid: a cursor row field has no further fields.");
			}

			$cursorName = $this->scope->getRowCursor($rowName);

			if (!$this->scope->getCursorQuery($cursorName)->hasValueAlias($field->getName())) {
				throw new SemanticException("Cursor '{$cursorName}' has no field '{$field->getName()}'.");
			}

			$node->setName($cursorName);
			$node->setType(IdentifierType::CursorRoot);
			$field->setType(IdentifierType::CursorField);
		}

		/**
		 * Types `paramName.field`, reading a `trigger` routine's entity-row parameter against
		 * mapped column metadata. Bare `paramName` with no field is always invalid: EQUEL has no
		 * row-construction mechanism, so a whole row value is never a legal operand anywhere in
		 * the routine body (see objectquel-equel-triggers-design.md).
		 * @param AstIdentifier $node Root identifier naming an entity-row parameter
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function resolveEntityRowField(AstIdentifier $node): void {
			$paramName = $node->getName();
			$field = $node->getNext();

			if ($field === null) {
				throw new SemanticException("'{$paramName}' is a row parameter and not a value. Read its fields as '{$paramName}.field'.");
			}

			if ($field->getNext() !== null) {
				throw new SemanticException("'{$node->getCompleteName()}' is invalid: a row parameter field has no further fields.");
			}

			$entityClass = $this->scope->getEntityRowClass($paramName);
			$columnName = $this->entityStore->getMetadata($entityClass)->getColumnName($field->getName());

			if ($columnName === null) {
				throw new SemanticException("'{$entityClass}' has no mapped column '{$field->getName()}' to read through '{$paramName}.{$field->getName()}'.");
			}

			$node->setType(IdentifierType::EntityRowRoot);
			$field->setType(IdentifierType::EntityRowField);
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
