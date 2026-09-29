<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * Base for procedural statements owning a single `{ }` body.
	 */
	abstract class AstStatementBlock extends Ast {

		/** @var AstInterface[] */
		private array $body;

		/**
		 * @param AstInterface[] $body Statements inside the block
		 */
		public function __construct(array $body) {
			$this->body = $body;

			foreach ($this->body as $statement) {
				$statement->setParent($this);
			}
		}

		/**
		 * Visits this node, then its body statements.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->acceptBody($visitor);
		}

		/**
		 * @return AstInterface[] Statements inside the block
		 */
		public function getBody(): array {
			return $this->body;
		}

		/**
		 * Visits each body statement in order.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		protected function acceptBody(AstVisitorInterface $visitor): void {
			foreach ($this->body as $statement) {
				$statement->accept($visitor);
			}
		}
	}
