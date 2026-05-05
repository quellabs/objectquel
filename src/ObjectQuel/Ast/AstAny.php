<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\ChildAstInterface;
	
	/**
	 * Class AstAny
	 *
	 * Implements ChildAstInterface because ANY(...) always appears inside a
	 * SELECT or WHERE clause — it can never be the query root. This lets callers
	 * call getParent() without a null-check and get a hard guarantee from the
	 * type system rather than a runtime LogicException in every optimizer.
	 */
	class AstAny extends AstAggregate implements ChildAstInterface {
		
		/**
		 * Returns the parent node.
		 * Narrows the return type from ?AstInterface to AstInterface.
		 * Throws if the tree is in an invalid state (parent was never set).
		 * @return AstInterface
	 */
		public function getParent(): AstInterface {
			$parent = parent::getParent();
			
			if ($parent === null) {
				throw new \LogicException('AstAny has no parent — the AST is in an invalid state.');
			}
			
			return $parent;
		}
		
		/**
		 * Rejects null to enforce the ChildAstInterface contract.
		 * AstAny cannot be detached from the tree.
		 * @param AstInterface|null $parent
		 * @return void
		 */
		public function setParent(?AstInterface $parent): void {
			if ($parent === null) {
				throw new \LogicException('AstAny cannot be made a root node.');
			}
			
			parent::setParent($parent);
		}

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "ANY";
		}
	}