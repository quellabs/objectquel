<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `call name(args)`: runs a routine on its own. A procedure yields nothing, a function one row.
	 */
	class AstCall extends Ast implements AstStatement {

		private AstRoutineCall $call;

		/**
		 * @param AstRoutineCall $call The routine and its arguments
		 */
		public function __construct(AstRoutineCall $call) {
			$this->call = $call;
			$call->setParent($this);
		}

		/**
		 * Visits this node, then the call.
		 * @param AstVisitorInterface $visitor The visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->call->accept($visitor);
		}

		/**
		 * @return AstRoutineCall The routine and its arguments
		 */
		public function getCall(): AstRoutineCall {
			return $this->call;
		}

		/**
		 * @return static A copy with a cloned call
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->call->deepClone());
		}
	}
