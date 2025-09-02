<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class AstNodeReplacer {
		
		/**
		 * Replaces a child node in its parent with a new node
		 * @param AstInterface $parent The parent node
		 * @param AstInterface $oldChild The child to replace
		 * @param AstInterface $newChild The replacement child
		 * @throws \InvalidArgumentException When parent type is not supported
		 */
		public function replaceChild(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			if ($parent instanceof AstRetrieve) {
				$this->replaceInRetrieve($parent, $oldChild, $newChild);
			} elseif ($parent instanceof AstAlias) {
				$this->replaceInAlias($parent, $oldChild, $newChild);
			} elseif ($parent instanceof AstSubquery) {
				$this->replaceInSubquery($parent, $oldChild, $newChild);
			} elseif ($this->isBinaryOperationNode($parent)) {
				$this->replaceInBinaryOperation($parent, $oldChild, $newChild);
			} elseif ($this->isAggregateNode($parent)) {
				$this->replaceInAggregate($parent, $oldChild, $newChild);
			} else {
				throw new \InvalidArgumentException("Unsupported parent type: " . get_class($parent));
			}
			
			// Set new parent relationship
			$newChild->setParent($parent);
		}
		
		/**
		 * Replace child in AstRetrieve node
		 * @param AstRetrieve $parent
		 * @param AstInterface $oldChild
		 * @param AstInterface $newChild
		 * @return void
		 */
		private function replaceInRetrieve(AstRetrieve $parent, AstInterface $oldChild, AstInterface $newChild): void {
			$location = $parent->getLocationOfChild($oldChild);
			
			switch ($location) {
				case 'conditions' :
					$parent->setConditions($newChild);
					break;
					
				case 'order_by' :
					// Handle order by replacement if needed
					// This would depend on how your AstRetrieve implements order by
					throw new \InvalidArgumentException("Order by replacement not yet implemented");
			}
		}
		
		/**
		 * Replace child in AstAlias node
		 * @param AstAlias $parent
		 * @param AstInterface $oldChild
		 * @param AstInterface $newChild
		 * @return void
		 */
		private function replaceInAlias(AstAlias $parent, AstInterface $oldChild, AstInterface $newChild): void {
			if ($parent->getExpression() === $oldChild) {
				$parent->setExpression($newChild);
			} else {
				throw new \InvalidArgumentException("Child not found in AstAlias");
			}
		}
		
		private function replaceInSubquery(AstSubquery $parent, AstInterface $oldChild, AstInterface $newChild): void {
			$parent->setAggregation($newChild);
		}
		
		/**
		 * Replace child in binary operation nodes (AstBinaryOperator, AstTerm, AstFactor, AstExpression)
		 * @param AstInterface $parent
		 * @param AstInterface $oldChild
		 * @param AstInterface $newChild
		 * @return void
		 */
		private function replaceInBinaryOperation(AstInterface $parent, AstInterface $oldChild, AstInterface $newChild): void {
			if ($parent->getLeft() === $oldChild) {
				$parent->setLeft($newChild);
			} elseif ($parent->getRight() === $oldChild) {
				$parent->setRight($newChild);
			} else {
				throw new \InvalidArgumentException("Child not found in binary operation node");
			}
		}
		
		/**
		 * Replace child in aggregate nodes (Sum, Count, Any, etc.)
		 * @param AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $parent
		 * @param AstInterface $oldChild
		 * @param AstInterface $newChild
		 * @return void
		 */
		private function replaceInAggregate(
			AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $parent,
			AstInterface $oldChild,
			AstInterface $newChild
		): void {
			$replaced = false;
			
			// Handle identifier replacement
			if ($parent->getIdentifier() === $oldChild) {
				$parent->setIdentifier($newChild);
				$replaced = true;
			}
			
			// Handle conditions replacement
			if ($parent->getConditions() === $oldChild) {
				$parent->setConditions($newChild);
				$replaced = true;
			}
			
			// Handle any other aggregate-specific children
			// Add more cases as needed based on your aggregate node structures
			if (!$replaced) {
				throw new \InvalidArgumentException("Child not found in aggregate node: " . get_class($parent));
			}
		}
		
		/**
		 * Check if node supports binary operations (has left/right children)
		 */
		private function isBinaryOperationNode(AstInterface $node): bool {
			return
				$node instanceof AstTerm ||
				$node instanceof AstBinaryOperator ||
				$node instanceof AstExpression ||
				$node instanceof AstFactor;
		}
		
		/**
		 * Check if node is an aggregate function
		 */
		private function isAggregateNode(AstInterface $node): bool {
			return
				$node instanceof AstSum ||
				$node instanceof AstSumU ||
				$node instanceof AstCount ||
				$node instanceof AstCountU ||
				$node instanceof AstAvg ||
				$node instanceof AstAvgU ||
				$node instanceof AstMin ||
				$node instanceof AstMax ||
				$node instanceof AstAny;
		}
	}
