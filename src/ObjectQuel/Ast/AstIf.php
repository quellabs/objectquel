<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `if condition { ... } [else { ... }]`
	 */
	class AstIf extends Ast {

		private AstInterface $condition;

		/** @var AstInterface[] */
		private array $thenBody;

		/** @var AstInterface[]|null Null when there is no else branch */
		private ?array $elseBody;

		/**
		 * @param AstInterface $condition Branch condition
		 * @param AstInterface[] $thenBody Statements run when the condition holds
		 * @param AstInterface[]|null $elseBody Statements run otherwise, or null when there is no else branch
		 */
		public function __construct(AstInterface $condition, array $thenBody, ?array $elseBody) {
			$this->condition = $condition;
			$this->thenBody = $thenBody;
			$this->elseBody = $elseBody;
			$this->condition->setParent($this);

			foreach ([...$this->thenBody, ...($this->elseBody ?? [])] as $statement) {
				$statement->setParent($this);
			}
		}

		/**
		 * Visits this node, then the condition, then the then- and else-branch statements.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->condition->accept($visitor);

			foreach ([...$this->thenBody, ...($this->elseBody ?? [])] as $statement) {
				$statement->accept($visitor);
			}
		}

		/**
		 * @return AstInterface Branch condition
		 */
		public function getCondition(): AstInterface {
			return $this->condition;
		}

		/**
		 * @return AstInterface[] Statements run when the condition holds
		 */
		public function getThenBody(): array {
			return $this->thenBody;
		}

		/**
		 * @return AstInterface[]|null Statements run otherwise, or null when there is no else branch
		 */
		public function getElseBody(): ?array {
			return $this->elseBody;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			$elseBody = $this->elseBody === null ? null : $this->cloneArray($this->elseBody);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->condition->deepClone(), $this->cloneArray($this->thenBody), $elseBody);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
