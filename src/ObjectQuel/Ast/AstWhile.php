<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `while condition { ... }`
	 */
	class AstWhile extends AstStatementBlock {

		private AstInterface $condition;

		/**
		 * @param AstInterface $condition Loop condition, evaluated before each iteration
		 * @param AstInterface[] $body Statements inside the loop
		 */
		public function __construct(AstInterface $condition, array $body) {
			parent::__construct($body);
			$this->condition = $condition;
			$this->condition->setParent($this);
		}

		/**
		 * Visits this node, then the condition, then the body statements.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			$visitor->visitNode($this);
			$this->condition->accept($visitor);
			$this->acceptBody($visitor);
		}

		/**
		 * @return AstInterface Loop condition
		 */
		public function getCondition(): AstInterface {
			return $this->condition;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->condition->deepClone(), $this->cloneArray($this->getBody()));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
